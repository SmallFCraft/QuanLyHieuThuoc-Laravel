<?php

namespace App\Services;

use App\Mail\NhacUongThuocMail;
use App\Models\NhacUongThuoc;
use App\Models\DonHang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Builds and dispatches medication reminders derived from completed orders.
 *
 * Dosage frequency is parsed from the free-text `products.usage` column written
 * by the pharmacy staff. When the text cannot be parsed the schedule falls back
 * to once a day — under-reminding is safe, guessing a higher frequency is not.
 */
class NhacUongThuocService
{
    /** Reminders only fire between these hours, matching NhacUongThuoc::advanceSchedule(). */
    private const DAY_START_HOUR = 8;
    private const DAY_END_HOUR = 20;

    /** Waking hours available for spacing doses. */
    private const WAKING_HOURS = 12;

    private const MAX_TIMES_PER_DAY = 6;
    private const MAX_DURATION_DAYS = 30;

    /** `medication_reminders.dosage_text` is a VARCHAR; `products.usage` is TEXT. */
    private const MAX_DOSAGE_TEXT = 255;

    /**
     * Create (or refresh) one reminder per distinct product in the order.
     *
     * Quantities are summed per product first: FEFO splits one purchase into one
     * `order_items` row per batch, so iterating rows directly would overwrite the
     * reminder and derive the course length from the last (smallest) batch.
     *
     * Idempotent: keyed on ma_dh + ma_sp so replaying a status change cannot
     * duplicate a schedule.
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
     * `san_phams.cach_dung` is free text written through a WYSIWYG editor, so it can be
     * longer than the column and can carry markup. Truncate rather than let a
     * strict-mode insert abort the order transaction.
     */
    private function vanBanLieuLuong(\App\Models\SanPham $sanPham): string
    {
        $goc = trim(strip_tags((string) ($sanPham->cach_dung ?? '')));
        $vanBan = $goc !== '' ? $goc : (string) $sanPham->ten;

        return mb_substr($vanBan, 0, self::MAX_DOSAGE_TEXT);
    }

    /**
     * Doses per day extracted from Vietnamese usage text.
     *
     * Recognised shapes, in priority order:
     *   "x 2-3 lan/ngay"      -> upper bound (3)
     *   "moi 4-6 gio"         -> waking hours / shortest interval (12/4 = 3)
     *   "1 vien/ngay"         -> single daily dose (1)
     * Anything else returns 1 rather than inventing a frequency.
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

        // "moi 4-6 gio" — shortest stated interval spread over waking hours only
        if (preg_match('/mỗi\s*(\d+)\s*(?:-\s*(\d+))?\s*giờ/u', $vanBan, $khop)) {
            $soGoiGiuaCacLan = max(1, (int) $khop[1]);

            return $this->gioiHanSoLan((int) floor(self::WAKING_HOURS / $soGoiGiuaCacLan));
        }

        // "1 vien/ngay", "15-30ml/ngay" — one dosing event per day
        if (preg_match('/\/\s*ngày/u', $vanBan)) {
            return 1;
        }

        return 1;
    }

    /**
     * Send every due reminder and roll its schedule forward.
     */
    public function guiDungHan(): int
    {
        $daGui = 0;

        NhacUongThuoc::due()
            // withTrashed on the product: a soft-deleted (discontinued) product is
            // still a course the patient must finish, so it must not cancel the schedule.
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
                        // A broken mailbox must not stall the rest of the batch.
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
     * Stop one specific schedule. Keyed on the row, not the product: the same
     * medicine can be on two courses from two orders, and cancelling one must not
     * silently stop the other.
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
     * Stop reminding a specific user about a specific product, across every order.
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
     * How long the dispensed quantity lasts at the parsed frequency.
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
