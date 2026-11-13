<?php

namespace App\Services;

use App\Models\SanPham;
use App\Models\LoSanPham;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class TonKhoService
{
    /**
     * Adjust stock quantity for a product.
     *
     * @param int $productId
     * @param int $quantity Positive for increase, negative for decrease
     * @param string $reason Reason code for the adjustment
     * @param int $userId User performing the adjustment
     * @return bool
     * @throws \Exception
     */
    public function dieuChinhTonKho(int $productId, int $quantity, string $reason, int $userId): bool
    {
        return DB::transaction(function () use ($productId, $quantity, $reason, $userId) {
            $sanPham = SanPham::lockForUpdate()->findOrFail($productId);

            $coCacLo = $sanPham->cacLo()->exists();
            if ($coCacLo) {
                $sanPham->cacLo()->orderBy('ngay_het_han')->orderBy('ma_lo')->lockForUpdate()->get();
                $soLuongCu = (int) $sanPham->cacLo()->sum('so_luong_ton');
            } else {
                $soLuongCu = (int) ($sanPham->getAttributes()['so_luong_ton'] ?? 0);
            }

            $soLuongMoi = $soLuongCu + $quantity;
            if ($soLuongMoi < 0) {
                throw new \Exception('Số lượng tồn kho không thể âm');
            }

            if ($coCacLo) {
                $this->apDungBienDoTonLo($sanPham, $quantity);
                $soLuongMoi = (int) $sanPham->cacLo()->sum('so_luong_ton');
                // Sync stock_quantity to products table for consistency
                DB::table('san_phams')
                    ->where('ma_sp', $sanPham->ma_sp)
                    ->update(['so_luong_ton' => $soLuongMoi, 'updated_at' => now()]);
            } else {
                $sanPham->setAttribute('so_luong_ton', $soLuongMoi);
                $sanPham->save();
            }

            app(NhatKyKiemToanService::class)->ghi(
                action: 'inventory_adjustment',
                modelType: SanPham::class,
                modelId: $productId,
                oldValues: ['so_luong_ton' => $soLuongCu],
                newValues: [
                    'so_luong_ton' => $soLuongMoi,
                    'adjustment' => $quantity,
                    'reason' => $reason
                ]
            );

            Log::info('Inventory adjusted', [
                'ma_sp' => $productId,
                'old_quantity' => $soLuongCu,
                'new_quantity' => $soLuongMoi,
                'adjustment' => $quantity,
                'reason' => $reason,
                'ma_nd' => $userId
            ]);

            return true;
        });
    }

    /**
     * Apply a stock delta across product batches (FEFO for decreases).
     *
     * @throws \Exception
     */
    private function apDungBienDoTonLo(SanPham $sanPham, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        if ($delta > 0) {
            $soLo = $sanPham->getAttributes()['so_lo'] ?? null;
            $lo = null;
            if ($soLo !== null && $soLo !== '') {
                $lo = $sanPham->cacLo()
                    ->where('so_lo', $soLo)
                    ->first();
            }
            if (! $lo instanceof LoSanPham) {
                $lo = $sanPham->cacLo()->orderBy('ngay_het_han')->orderBy('ma_lo')->first();
            }
            if (! $lo instanceof LoSanPham) {
                throw new \Exception('Không tìm thấy lô sản phẩm để điều chỉnh tồn kho.');
            }
            $lo->so_luong_ton += $delta;
            $lo->save();

            return;
        }

        $conLai = abs($delta);
        foreach ($sanPham->cacLo()->orderBy('ngay_het_han')->orderBy('ma_lo')->get() as $lo) {
            if ($conLai <= 0) {
                break;
            }
            if ($lo->so_luong_ton <= 0) {
                continue;
            }
            $soLuongLay = min($lo->so_luong_ton, $conLai);
            $lo->so_luong_ton -= $soLuongLay;
            $lo->save();
            $conLai -= $soLuongLay;
        }
        if ($conLai > 0) {
            throw new \Exception('Số lượng tồn kho không thể âm');
        }
    }

    /**
     * Check for products expiring within specified days.
     *
     * @param int $daysThreshold Number of days to check (default: 30)
     * @return Collection
     */
    public function kiemTraHetHan(int $daysThreshold = 30): Collection
    {
        return SanPham::with('danhMuc')
            ->where('ngay_het_han', '<=', Carbon::now()->addDays($daysThreshold))
            ->where('ngay_het_han', '>', Carbon::now())
            ->orderBy('ngay_het_han', 'asc')
            ->get();
    }

    /**
     * Get products with low stock (quantity <= reorder point).
     *
     * @return Collection
     */
    public function laySanPhamSapHet(): Collection
    {
        return SanPham::with('danhMuc')
            ->lowStock()
            ->orderBy('so_luong_ton', 'asc')
            ->get();
    }

    /**
     * Get inventory summary statistics.
     *
     * Key names match what admin/inventory/index.blade.php reads. The view falls
     * back to `?? 0`, so a rename here shows four zero cards above a table that
     * lists the very rows being counted, with nothing failing loudly.
     *
     * @return array
     */
    public function layTongQuanTonKho(): array
    {
        return [
            'total_products' => SanPham::count(),
            'total_value' => SanPham::sum(DB::raw('ROUND(gia, 0) * so_luong_ton')),
            'low_stock' => SanPham::lowStock()->count(),
            'expiring_soon' => SanPham::expiringSoon(30)->count(),
            'expired' => SanPham::where('ngay_het_han', '<', Carbon::now())->count(),
            'out_of_stock' => SanPham::where('so_luong_ton', 0)->count(),
        ];
    }
}
