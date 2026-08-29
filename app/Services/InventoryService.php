<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
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
            $product = Product::lockForUpdate()->findOrFail($productId);

            $hasBatches = $product->batches()->exists();
            if ($hasBatches) {
                $product->batches()->orderBy('expiration_date')->orderBy('MaLo')->lockForUpdate()->get();
                $oldQuantity = (int) $product->batches()->sum('stock_quantity');
            } else {
                $oldQuantity = (int) ($product->getAttributes()['stock_quantity'] ?? 0);
            }

            $newQuantity = $oldQuantity + $quantity;
            if ($newQuantity < 0) {
                throw new \Exception('Số lượng tồn kho không thể âm');
            }

            if ($hasBatches) {
                $this->applyBatchStockDelta($product, $quantity);
                $newQuantity = (int) $product->batches()->sum('stock_quantity');
                // Sync stock_quantity to products table for consistency
                DB::table('products')
                    ->where('MaSP', $product->MaSP)
                    ->update(['stock_quantity' => $newQuantity, 'updated_at' => now()]);
            } else {
                $product->setAttribute('stock_quantity', $newQuantity);
                $product->save();
            }

            app(AuditLogService::class)->log(
                action: 'inventory_adjustment',
                modelType: Product::class,
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
    private function applyBatchStockDelta(Product $product, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        if ($delta > 0) {
            $batchNumber = $product->getAttributes()['batch_number'] ?? null;
            $batch = null;
            if ($batchNumber !== null && $batchNumber !== '') {
                $batch = $product->batches()
                    ->where('batch_number', $batchNumber)
                    ->first();
            }
            if (! $batch instanceof ProductBatch) {
                $batch = $product->batches()->orderBy('expiration_date')->orderBy('MaLo')->first();
            }
            if (! $batch instanceof ProductBatch) {
                throw new \Exception('Không tìm thấy lô sản phẩm để điều chỉnh tồn kho.');
            }
            $batch->stock_quantity += $delta;
            $batch->save();

            return;
        }

        $remaining = abs($delta);
        foreach ($product->batches()->orderBy('expiration_date')->orderBy('MaLo')->get() as $batch) {
            if ($remaining <= 0) {
                break;
            }
            if ($batch->stock_quantity <= 0) {
                continue;
            }
            $take = min($batch->stock_quantity, $remaining);
            $batch->stock_quantity -= $take;
            $batch->save();
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
        return Product::with('category')
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
        return Product::with('category')
            ->lowStock()
            ->orderBy('stock_quantity', 'asc')
            ->get();
    }

    /**
     * Get inventory summary statistics.
     *
     * @return array
     */
    public function getInventorySummary(): array
    {
        return [
            'total_products' => Product::count(),
            'total_value' => Product::sum(DB::raw('ROUND(price, 0) * stock_quantity')),
            'low_stock_count' => Product::lowStock()->count(),
            'expiring_soon_count' => Product::expiringSoon(30)->count(),
            'expired_count' => Product::where('expiration_date', '<', Carbon::now())->count(),
            'out_of_stock_count' => Product::where('stock_quantity', 0)->count(),
        ];
    }
}
