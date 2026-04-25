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
                $product->batches()->orderBy('expiration_date')->orderBy('id')->lockForUpdate()->get();
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
                    ->where('id', $product->id)
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
                'product_id' => $productId,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'adjustment' => $quantity,
                'reason' => $reason,
                'user_id' => $userId
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
                $batch = $product->batches()->orderBy('expiration_date')->orderBy('id')->first();
            }
            if (! $batch instanceof ProductBatch) {
                throw new \Exception('Không tìm thấy lô sản phẩm để điều chỉnh tồn kho.');
            }
            $batch->stock_quantity += $delta;
            $batch->save();

            return;
        }

        $remaining = abs($delta);
        foreach ($product->batches()->orderBy('expiration_date')->orderBy('id')->get() as $batch) {
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
     * Validate stock availability for multiple items.
     *
     * @param array $items Array of ['product_id' => id, 'quantity' => qty]
     * @return bool
     * @throws \Exception
     */
    public function validateStockAvailability(array $items): bool
    {
        foreach ($items as $item) {
            $product = Product::find($item['product_id']);
            
            if (!$product) {
                throw new \Exception("Sản phẩm không tồn tại (ID: {$item['product_id']})");
            }
            
            if ($product->stock_quantity < $item['quantity']) {
                throw new \Exception(
                    "Sản phẩm '{$product->name}' không đủ số lượng. " .
                    "Có sẵn: {$product->stock_quantity}, Yêu cầu: {$item['quantity']}"
                );
            }
            
            if ($product->isExpired()) {
                throw new \Exception("Sản phẩm '{$product->name}' đã hết hạn");
            }
        }
        
        return true;
    }

    /**
     * Apply FEFO (First Expired, First Out) strategy.
     * Returns the product with the nearest expiration date.
     *
     * @param int $productId
     * @return Product|null
     */
    public function applyFEFO(int $productId): ?Product
    {
        return Product::where('id', $productId)
            ->where('stock_quantity', '>', 0)
            ->notExpired()
            ->orderBy('expiration_date', 'asc')
            ->first();
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

    /**
     * Get products sorted by FEFO strategy.
     *
     * @return Collection
     */
    public function getProductsByFEFO(): Collection
    {
        return Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->FEFO()
            ->get();
    }

    /**
     * Deduct stock for multiple items (used during checkout).
     *
     * @param array $items Array of ['product_id' => id, 'quantity' => qty]
     * @param int $userId User performing the operation
     * @return bool
     * @throws \Exception
     */
    public function deductStock(array $items, int $userId): bool
    {
        return DB::transaction(function () use ($items, $userId) {
            foreach ($items as $item) {
                // Lock the product row
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);
                
                // Validate stock availability
                if ($product->stock_quantity < $item['quantity']) {
                    throw new \Exception(
                        "Không đủ số lượng cho sản phẩm '{$product->name}'"
                    );
                }
                
                $oldQuantity = $product->stock_quantity;
                
                // Deduct from batches if they exist (FEFO strategy)
                $hasBatches = $product->batches()->exists();
                if ($hasBatches) {
                    $this->applyBatchStockDelta($product, -$item['quantity']);
                    $newQuantity = (int) $product->batches()->sum('stock_quantity');
                    // Sync to products table
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['stock_quantity' => $newQuantity, 'updated_at' => now()]);
                } else {
                    // Deduct directly from product
                    $product->stock_quantity -= $item['quantity'];
                    $product->save();
                    $newQuantity = $product->stock_quantity;
                }
                
                // Log the deduction
                app(AuditLogService::class)->log(
                    action: 'stock_deduction',
                    modelType: Product::class,
                    modelId: $product->id,
                    oldValues: ['stock_quantity' => $oldQuantity],
                    newValues: [
                        'stock_quantity' => $newQuantity,
                        'deducted' => $item['quantity']
                    ]
                );
            }
            
            return true;
        });
    }

    /**
     * Restore stock for multiple items (used during order cancellation).
     *
     * @param array $items Array of ['product_id' => id, 'quantity' => qty]
     * @param int $userId User performing the operation
     * @return bool
     * @throws \Exception
     */
    public function restoreStock(array $items, int $userId): bool
    {
        return DB::transaction(function () use ($items, $userId) {
            foreach ($items as $item) {
                // Lock the product row
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);
                
                $oldQuantity = $product->stock_quantity;
                
                // Restore to batches if they exist
                $hasBatches = $product->batches()->exists();
                if ($hasBatches) {
                    $this->applyBatchStockDelta($product, $item['quantity']);
                    $newQuantity = (int) $product->batches()->sum('stock_quantity');
                    // Sync to products table
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['stock_quantity' => $newQuantity, 'updated_at' => now()]);
                } else {
                    // Restore directly to product
                    $product->stock_quantity += $item['quantity'];
                    $product->save();
                    $newQuantity = $product->stock_quantity;
                }
                
                // Log the restoration
                app(AuditLogService::class)->log(
                    action: 'stock_restoration',
                    modelType: Product::class,
                    modelId: $product->id,
                    oldValues: ['stock_quantity' => $oldQuantity],
                    newValues: [
                        'stock_quantity' => $newQuantity,
                        'restored' => $item['quantity']
                    ]
                );
            }
            
            return true;
        });
    }
}
