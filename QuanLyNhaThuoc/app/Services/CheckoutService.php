<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckoutService
{
    /**
     * Process checkout with transaction safety.
     *
     * @param array $cartItems
     * @param int $userId
     * @param array $shippingInfo
     * @return Order
     * @throws \Exception
     */
    public function processCheckout(array $cartItems, int $userId, array $shippingInfo): Order
    {
        // OPTIMIZED: Load all products in 1 query instead of N+1
        $productIds = array_column($cartItems, 'product_id');
        $products = Product::whereIn('id', $productIds)
            ->get()
            ->keyBy('id');
        
        // Quick validation BEFORE transaction (fail fast)
        $errors = [];
        $totalAmount = 0;
        
        foreach ($cartItems as $item) {
            $product = $products[$item['product_id']] ?? null;
            
            if (!$product) {
                $errors[] = "Sản phẩm không tồn tại.";
                continue;
            }
            
            // Check total stock across all batches
            $totalStock = ProductBatch::where('product_id', $product->id)
                ->inStock()
                ->notExpired()
                ->sum('stock_quantity');
            
            if ($totalStock < $item['quantity']) {
                $errors[] = "Sản phẩm {$product->name} không đủ số lượng. Còn lại: {$totalStock}.";
            }
            
            $totalAmount += $product->price * $item['quantity'];
        }
        
        if (!empty($errors)) {
            throw new \Exception(implode(' ', $errors));
        }

        DB::beginTransaction();
        
        try {
            // Create order
            $order = Order::create([
                'user_id' => $userId,
                'total_amount' => $totalAmount,
                'status' => 'Pending',
                'shipping_address' => $shippingInfo['address'],
                'shipping_phone' => $shippingInfo['phone'],
            ]);

            // Create order items and update inventory with FEFO strategy
            foreach ($cartItems as $item) {
                $remainingQty = $item['quantity'];
                $productId = $item['product_id'];
                
                // Get batches using FEFO (First Expired, First Out)
                $batches = ProductBatch::where('product_id', $productId)
                    ->inStock()
                    ->notExpired()
                    ->FEFO()
                    ->lockForUpdate()
                    ->get();
                
                if ($batches->sum('stock_quantity') < $remainingQty) {
                    $product = Product::find($productId);
                    throw new \Exception("Sản phẩm {$product->name} không đủ số lượng trong kho.");
                }
                
                // Deduct from batches following FEFO
                foreach ($batches as $batch) {
                    if ($remainingQty <= 0) break;
                    
                    $deductQty = min($remainingQty, $batch->stock_quantity);
                    
                    // Create order item for this batch
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'batch_id' => $batch->id,
                        'quantity' => $deductQty,
                        'unit_price' => $products[$productId]->price,
                        'batch_number' => $batch->batch_number,
                    ]);
                    
                    // Deduct stock from batch
                    $batch->stock_quantity -= $deductQty;
                    $batch->save();
                    
                    $remainingQty -= $deductQty;
                }
                
                if ($remainingQty > 0) {
                    throw new \Exception("Không thể phân bổ đủ hàng cho sản phẩm.");
                }
            }

            DB::commit();

            return $order;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Checkout transaction failed', [
                'user_id' => $userId,
                'cart_items' => $cartItems,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new \Exception('Không thể hoàn tất đơn hàng. Vui lòng thử lại.');
        }
    }

    /**
     * Validate cart items.
     * DEPRECATED: Validation now done in processCheckout()
     *
     * @param array $cartItems
     * @return array Array of error messages
     */
    public function validateCart(array $cartItems): array
    {
        // This method kept for backward compatibility
        // Real validation happens in processCheckout() with locks
        return [];
    }

    /**
     * Calculate total amount for cart items.
     * DEPRECATED: Calculation now done in processCheckout()
     *
     * @param array $cartItems
     * @return float
     */
    public function calculateTotal(array $cartItems): float
    {
        // This method kept for backward compatibility
        // Real calculation happens in processCheckout()
        $productIds = array_column($cartItems, 'product_id');
        $products = Product::whereIn('id', $productIds)->get()->keyBy('id');
        
        $total = 0;
        foreach ($cartItems as $item) {
            if (isset($products[$item['product_id']])) {
                $total += $products[$item['product_id']]->price * $item['quantity'];
            }
        }
        
        return $total;
    }
}
