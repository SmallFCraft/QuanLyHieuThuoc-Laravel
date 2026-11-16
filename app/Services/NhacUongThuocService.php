<?php

namespace App\Services;

use App\Mail\NhacUongThuocMail;
use App\Models\NhacUongThuoc;
use App\Models\DonHang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Dùng và gửi các nhắc uống thuốc dựa trên đơn hàng đã hoàn thành.
 *
 * Tần suất liều lượng được phân tích từ cột văn bản tự do `products.usage` do nhân viên
 * nhà thuốc viết. Khi văn bản không thể phân tích được, lịch quay về một lần một ngày —
 * nhắc ít hơn là an toàn, đoán một tần suất cao hơn thì không.
 */
class NhacUongThuocService
{
    /** Nhắc chỉ chạy trong khoảng giờ này, khớp với NhacUongThuoc::advanceSchedule(). */
    private const DAY_START_HOUR = 8;
    private const DAY_END_HOUR = 20;

    /** Số giờ thức để phân bổ các lần liều lượng. */
    private const WAKING_HOURS = 12;

    private const MAX_TIMES_PER_DAY = 6;
    private const MAX_DURATION_DAYS = 30;

    /** `medication_reminders.dosage_text` là VARCHAR; `products.usage` là TEXT. */
    private const MAX_DOSAGE_TEXT = 255;

    /**
     * Tạo (hoặc làm mới) một nhắc uống cho mỗi sản phẩm phân biệt trong đơn hàng.
     *
     * Số lượng được cộng theo từng sản phẩm trước: FEFO tách một lần mua thành một
     * dòng `order_items` cho mỗi lô, nên lặp trực tiếp các dòng sẽ ghi đè nhắc uống
     * và tính độ dài khóa điều trị từ lô cuối cùng (nhỏ nhất).
     *
     * Idempotent: key theo ma_dh + ma_sp nên phát lại một thay đổi trạng thái
     * không thể nhân đôi lịch.
     */
    public function taoTuDonHang(DonHang $donHang): int
    {
        $donHang->loadMissing('chiTietDonHangs.sanPham');

        $soLuongSanPham = [];
        $danhSachSanPham = [];

        foreach ($donHang->chiTietDonHangs as $chiTiet) {
            if (!$chiTiet->sanPham) {
                continue;
            }

            $maSp = (int) $chiTiet->ma_sp;
            $soLuongSanPham[$maSp] = ($soLuongSanPham[$maSp] ?? 0) + (int) $chiTiet->so_luong;
            $danhSachSanPham[$maSp] = $chiTiet->sanPham;
        }

        foreach ($soLuongSanPham as $maSp => $soLuong) {
            $sanPham = $danhSachSanPham[$maSp];
            $soLanMotNgay = $this->phanTichSoLanMotNgay($sanPham->cach_dung);

            NhacUongThuoc::updateOrCreate(
                [
                    'ma_dh' => $donHang->ma_dh,
                    'ma_sp' => $maSp,
                ],
                [
                    'ma_nd' => $donHang->ma_nd,
                    'dosage_text' => $this->vanBanLieuLuong($sanPham),
                    'times_per_day' => $soLanMotNgay,
                    'duration_days' => $this->soNgaySuDung($soLuong, $soLanMotNgay),
                    'start_date' => now()->toDateString(),
                    'next_remind_at' => $this->thoiDiemNhacDauTien(),
                    'status' => 'active',
                ]
            );
        }

        return count($soLuongSanPham);
    }

    /**
     * `san_phams.cach_dung` là văn bản tự do viết qua trình soạn thảo WYSIWYG, nên nó có thể
     * dài hơn cột và mang theo markup. Cắt bớt thay vì để một insert strict-mode làm
     * abort transaction của đơn hàng.
     */
    private function vanBanLieuLuong(\App\Models\SanPham $sanPham): string
    {
        $goc = trim(strip_tags((string) ($sanPham->cach_dung ?? '')));
        $vanBan = $goc !== '' ? $goc : (string) $sanPham->ten;

        return mb_substr($vanBan, 0, self::MAX_DOSAGE_TEXT);
    }

    /**
     * Số lần liều lượng mỗi ngày trích từ văn bản cách dùng tiếng Việt.
     *
     * Các dạng được nhận diện, theo thứ tự ưu tiên:
     *   "x 2-3 lần/ngày"      -> trần (3)
     *   "mỗi 4-6 giờ"         -> giờ thức / khoảng ngắn nhất (12/4 = 3)
     *   "1 viên/ngày"         -> một lần liều lượng một ngày (1)
     * Bất kỳ dạng khác trả về 1 thay vì tự bịa tần suất.
     */
    public function phanTichSoLanMotNgay(?string $cachDung): int
    {
        if ($cachDung === null || trim($cachDung) === '') {
            return 1;
        }

        $vanBan = mb_strtolower($cachDung, 'UTF-8');

        // "x 2-3 lần/ngày", "3 lần/ngày", "2 lần mỗi ngày"
        if (preg_match('/(\d+)\s*(?:-\s*(\d+))?\s*lần\s*(?:\/|mỗi\s*)ngày/u', $vanBan, $khop)) {
            $soLan = (int) ($khop[2] ?? $khop[1]);

            return $this->gioiHanSoLan($soLan);
        }

        // "mỗi 4-6 giờ" — khoảng ngắn nhất được nêu ra, phân bổ chỉ trong giờ thức
        if (preg_match('/mỗi\s*(\d+)\s*(?:-\s*(\d+))?\s*giờ/u', $vanBan, $khop)) {
            $soGoiGiuaCacLan = max(1, (int) $khop[1]);

            return $this->gioiHanSoLan((int) floor(self::WAKING_HOURS / $soGoiGiuaCacLan));
        }

        // "1 viên/ngày", "15-30ml/ngày" — một lần liều lượng mỗi ngày
        if (preg_match('/\/\s*ngày/u', $vanBan)) {
            return 1;
        }

        return 1;
    }

    /**
     * Gửi mỗi nhắc uống đến hạn và kéo lịch của nó lên phía trước.
     *
     * Bảng thiếu (môi trường chưa chạy migration mới) là một lượt im lặng, không
     * phải lỗi: cron 15 phút một lần đọc exit code 1 kèm stack trace cả ngày trên
     * hosting, trong khi lệnh này không có đối tượng nào để phục vụ.
     */
    public function guiDungHan(): int
    {
        if (! Schema::hasTable((new NhacUongThuoc)->getTable())) {
            return 0;
        }

        $daGui = 0;

        NhacUongThuoc::due()
            // withTrashed trên sản phẩm: sản phẩm đã soft-delete (ngừng kinh doanh) vẫn
            // là một khóa điều trị bệnh nhân phải hoàn thành, nên không được hủy lịch.
            ->with(['nguoiDung', 'sanPham' => fn ($query) => $query->withTrashed()])
            ->chunkById(100, function ($cacNhacUong) use (&$daGui) {
                foreach ($cacNhacUong as $nhacUong) {
                    if (!$nhacUong->nguoiDung || !$nhacUong->sanPham) {
                        $nhacUong->update(['status' => 'cancelled', 'next_remind_at' => null]);
                        continue;
                    }

                    try {
                        Mail::to($nhacUong->nguoiDung->email)->send(new NhacUongThuocMail($nhacUong));
                        $daGui++;
                    } catch (\Throwable $e) {
                        // Một mailbox lỗi không được làm stall phần còn lại của batch.
                        Log::warning('Medication reminder mail failed', [
                            'ma_nn' => $nhacUong->ma_nn,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $nhacUong->advanceSchedule();
                }
            }, 'ma_nn');

        return $daGui;
    }

    /**
     * Dừng một lịch cụ thể. Key trên dòng bản ghi, chứ không phải sản phẩm: cùng
     * một thuốc có thể nằm trong hai khóa từ hai đơn hàng, và hủy một khóa
     * không được im lặng dừng khóa kia.
     */
    public function huyLichNhac(NhacUongThuoc $nhacUong): void
    {
        if ($nhacUong->status !== 'active') {
            return;
        }

        $nhacUong->update([
            'status' => 'cancelled',
            'next_remind_at' => null,
        ]);
    }

    /**
     * Dừng nhắc cho một người dùng cụ thể về một sản phẩm cụ thể, trên mọi đơn hàng.
     */
    public function huyTheoNguoiDung(int $maNguoiDung, int $maSp): void
    {
        NhacUongThuoc::where('ma_nd', $maNguoiDung)
            ->where('ma_sp', $maSp)
            ->where('status', 'active')
            ->update([
                'status' => 'cancelled',
                'next_remind_at' => null,
            ]);
    }

    private function gioiHanSoLan(int $soLan): int
    {
        return max(1, min(self::MAX_TIMES_PER_DAY, $soLan));
    }

    /**
     * Số lượng đã xuất tồn được bao lâu với tần suất đã phân tích.
     */
    private function soNgaySuDung(int $soLuong, int $soLanMotNgay): int
    {
        $soNgay = (int) ceil(max(1, $soLuong) / max(1, $soLanMotNgay));

        return max(1, min(self::MAX_DURATION_DAYS, $soNgay));
    }

    private function thoiDiemNhacDauTien(): \Illuminate\Support\Carbon
    {
        $bayGio = now();

        if ($bayGio->hour < self::DAY_START_HOUR) {
            return $bayGio->copy()->setTime(self::DAY_START_HOUR, 0);
        }

        if ($bayGio->hour >= self::DAY_END_HOUR) {
            return $bayGio->copy()->addDay()->setTime(self::DAY_START_HOUR, 0);
        }

        return $bayGio;
    }
}
