<?php

namespace App\Services;

use App\Mail\NhacUongThuocMail;
use App\Models\NhacUongThuoc;
use App\Models\DonHang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Dung va gui cac nhac uong thuoc dua tren don hang da hoan thanh.
 *
 * Tan suat lieu luong duoc phan tich tu cot van ban tu do `products.usage` do nhan vien
 * nha thuoc viet. Khi van ban khong the phan tich duoc, lich quay ve mot lan mot ngay —
 * nhac it hon la an toan, doan mot tan suat cao hon thi khong.
 */
class NhacUongThuocService
{
    /** Nhac chi chay trong khoang gio nay, khop voi NhacUongThuoc::advanceSchedule(). */
    private const DAY_START_HOUR = 8;
    private const DAY_END_HOUR = 20;

    /** So gio thuc de phan bo cac lan lieu luong. */
    private const WAKING_HOURS = 12;

    private const MAX_TIMES_PER_DAY = 6;
    private const MAX_DURATION_DAYS = 30;

    /** `medication_reminders.dosage_text` la VARCHAR; `products.usage` la TEXT. */
    private const MAX_DOSAGE_TEXT = 255;

    /**
     * Tao (hoac lam moi) mot nhac uong cho moi san pham phan biet trong don hang.
     *
     * So luong duoc cong theo tung san pham truoc: FEFO tach mot lan mua thanh mot
     * dong `order_items` cho moi lo, nen lap truc tiep cac dong se ghi de nhac uong
     * va tinh do dai khoa dieu tri tu lo cuoi cung (nho nhat).
     *
     * Idempotent: key theo ma_dh + ma_sp nen phat lai mot thay doi trang thai
     * khong the nhan doi lich.
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
     * `san_phams.cach_dung` la van ban tu do viet qua trinh soan thao WYSIWYG, nen no co the
     * dai hon cot va mang theo markup. Cat bot thay vi de mot insert strict-mode lam
     * abort transaction cua don hang.
     */
    private function vanBanLieuLuong(\App\Models\SanPham $sanPham): string
    {
        $goc = trim(strip_tags((string) ($sanPham->cach_dung ?? '')));
        $vanBan = $goc !== '' ? $goc : (string) $sanPham->ten;

        return mb_substr($vanBan, 0, self::MAX_DOSAGE_TEXT);
    }

    /**
     * So lan lieu luong moi ngay trich tu van ban cach dung tieng Viet.
     *
     * Cac dang duoc nhan dien, theo thu tu uu tien:
     *   "x 2-3 lan/ngay"      -> tran (3)
     *   "moi 4-6 gio"         -> gio thuc / khoang ngan nhat (12/4 = 3)
     *   "1 vien/ngay"         -> mot lan lieu luong mot ngay (1)
     * Bat ky dang khac tra ve 1 thay vi tu bia tan suat.
     */
    public function phanTichSoLanMotNgay(?string $cachDung): int
    {
        if ($cachDung === null || trim($cachDung) === '') {
            return 1;
        }

        $vanBan = mb_strtolower($cachDung, 'UTF-8');

        // "x 2-3 lan/ngay", "3 lan/ngay", "2 lan moi ngay"
        if (preg_match('/(\d+)\s*(?:-\s*(\d+))?\s*lần\s*(?:\/|mỗi\s*)ngày/u', $vanBan, $khop)) {
            $soLan = (int) ($khop[2] ?? $khop[1]);

            return $this->gioiHanSoLan($soLan);
        }

        // "moi 4-6 gio" — khoang ngan nhat duoc neu ra, phan bo chi trong gio thuc
        if (preg_match('/mỗi\s*(\d+)\s*(?:-\s*(\d+))?\s*giờ/u', $vanBan, $khop)) {
            $soGoiGiuaCacLan = max(1, (int) $khop[1]);

            return $this->gioiHanSoLan((int) floor(self::WAKING_HOURS / $soGoiGiuaCacLan));
        }

        // "1 vien/ngay", "15-30ml/ngay" — mot lan lieu luong moi ngay
        if (preg_match('/\/\s*ngày/u', $vanBan)) {
            return 1;
        }

        return 1;
    }

    /**
     * Gui moi nhac uong den han va keo lich cua no len phia truoc.
     */
    public function guiDungHan(): int
    {
        $daGui = 0;

        NhacUongThuoc::due()
            // withTrashed tren san pham: san pham da soft-delete (ngung kinh doanh) van
            // la mot khoa dieu tri benh nhan phai hoan thanh, nen khong duoc huy lich.
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
                        // Mot mailbox loi khong duoc lam stall phan con lai cua batch.
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
     * Dung mot lich cu the. Key tren dong ban ghi, chu khong phai san pham: cung
     * mot thuoc co the nam trong hai khoa tu hai don hang, va huy mot khoa
     * khong duoc im lang dung khoa kia.
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
     * Dung nhac cho mot nguoi dung cu the ve mot san pham cu the, tren moi don hang.
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
     * So luong da xuat ton duoc bao lau voi tan suat da phan tich.
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
