<?php

namespace App\Services;

use App\Models\SanPham;
use App\Models\LoSanPham;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class InventoryService
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
    public function adjustStock(int $productId, int $quantity, string $reason, int $userId): bool
    {
        return DB::transaction(function () use ($productId, $quantity, $reason, $userId) {
            $sanPham = SanPham::lockForUpdate()->findOrFail($productId);

            $hasBatches = $sanPham->cacLo()->exists();
            if ($hasBatches) {
                $sanPham->cacLo()->orderBy('expiration_date')->orderBy('MaLo')->lockForUpdate()->get();
                $oldQuantity = (int) $sanPham->cacLo()->sum('stock_quantity');
            } else {
                $oldQuantity = (int) ($sanPham->getAttributes()['stock_quantity'] ?? 0);
            }

            $newQuantity = $oldQuantity + $quantity;
            if ($newQuantity < 0) {
                throw new \Exception('Số lượng tồn kho không thể âm');
            }

            if ($hasBatches) {
                $this->applyBatchStockDelta($sanPham, $quantity);
                $newQuantity = (int) $sanPham->cacLo()->sum('stock_quantity');
                // Sync stock_quantity to products table for consistency
                DB::table('products')
                    ->where('MaSP', $sanPham->MaSP)
                    ->update(['stock_quantity' => $newQuantity, 'updated_at' => now()]);
            } else {
                $sanPham->setAttribute('stock_quantity', $newQuantity);
                $sanPham->save();
            }

            app(AuditLogService::class)->log(
                action: 'inventory_adjustment',
                modelType: SanPham::class,
                modelId: $productId,
                oldValues: ['stock_quantity' => $oldQuantity],
                newValues: [
                    'stock_quantity' => $newQuantity,
                    'adjustment' => $quantity,
                    'reason' => $reason
                ]
            );

            Log::info('Inventory adjusted', [
                'MaSP' => $productId,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'adjustment' => $quantity,
                'reason' => $reason,
                'MaKH' => $userId
            ]);

            return true;
        });
    }

    /**
     * Apply a stock delta across product batches (FEFO for decreases).
     *
     * @throws \Exception
     */
    private function applyBatchStockDelta(SanPham $sanPham, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        if ($delta > 0) {
            $batchNumber = $sanPham->getAttributes()['batch_number'] ?? null;
            $lo = null;
            if ($batchNumber !== null && $batchNumber !== '') {
                $lo = $sanPham->cacLo()
                    ->where('batch_number', $batchNumber)
                    ->first();
            }
            if (! $lo instanceof LoSanPham) {
                $lo = $sanPham->cacLo()->orderBy('expiration_date')->orderBy('MaLo')->first();
            }
            if (! $lo instanceof LoSanPham) {
                throw new \Exception('Không tìm thấy lô sản phẩm để điều chỉnh tồn kho.');
            }
            $lo->stock_quantity += $delta;
            $lo->save();

            return;
        }

        $remaining = abs($delta);
        foreach ($sanPham->cacLo()->orderBy('expiration_date')->orderBy('MaLo')->get() as $lo) {
            if ($remaining <= 0) {
                break;
            }
            if ($lo->stock_quantity <= 0) {
                continue;
            }
            $take = min($lo->stock_quantity, $remaining);
            $lo->stock_quantity -= $take;
            $lo->save();
            $remaining -= $take;
        }
        if ($remaining > 0) {
            throw new \Exception('Số lượng tồn kho không thể âm');
        }
    }

    /**
     * Check for products expiring within specified days.
     *
     * @param int $daysThreshold Number of days to check (default: 30)
     * @return Collection
     */
    public function checkExpiration(int $daysThreshold = 30): Collection
    {
        return SanPham::with('danhMuc')
            ->where('expiration_date', '<=', Carbon::now()->addDays($daysThreshold))
            ->where('expiration_date', '>', Carbon::now())
            ->orderBy('expiration_date', 'asc')
            ->get();
    }

    /**
     * Get products with low stock (quantity <= reorder point).
     *
     * @return Collection
     */
    public function getLowStockProducts(): Collection
    {
        return SanPham::with('danhMuc')
            ->lowStock()
            ->orderBy('stock_quantity', 'asc')
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
    public function getInventorySummary(): array
    {
        return [
            'total_products' => SanPham::count(),
            'total_value' => SanPham::sum(DB::raw('ROUND(price, 0) * stock_quantity')),
            'low_stock' => SanPham::lowStock()->count(),
            'expiring_soon' => SanPham::expiringSoon(30)->count(),
            'expired' => SanPham::where('expiration_date', '<', Carbon::now())->count(),
            'out_of_stock' => SanPham::where('stock_quantity', 0)->count(),
        ];
    }
}
