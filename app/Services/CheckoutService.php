<?php

namespace App\Services;

use App\Models\DonHang;
use App\Models\ChiTietDonHang;
use App\Models\SanPham;
use App\Models\LoSanPham;
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
     * @return DonHang
     * @throws \Exception
     */
    public function processCheckout(array $cartItems, int $userId, array $shippingInfo): DonHang
    {
        // OPTIMIZED: Load all products in 1 query instead of N+1
        $productIds = array_column($cartItems, 'MaSP');
        $sanPhams = SanPham::whereIn('MaSP', $productIds)
            ->get()
            ->keyBy('MaSP');
        
        // Quick validation BEFORE transaction (fail fast)
        $errors = [];
        $totalAmount = 0;
        
        foreach ($cartItems as $item) {
            $sanPham = $sanPhams[$item['MaSP']] ?? null;
            
            if (!$sanPham) {
                $errors[] = "Sản phẩm không tồn tại.";
                continue;
            }
            
            // Check total stock across all batches
            $totalStock = LoSanPham::where('MaSP', $sanPham->MaSP)
                ->inStock()
                ->notExpired()
                ->sum('stock_quantity');
            
            if ($totalStock < $item['quantity']) {
                $errors[] = "Sản phẩm {$sanPham->name} không đủ số lượng. Còn lại: {$totalStock}.";
            }
            
            $totalAmount += $sanPham->price_vnd * $item['quantity'];
        }
        
        if (!empty($errors)) {
            throw new \Exception(implode(' ', $errors));
        }

        DB::beginTransaction();
        
        try {
            // Create order
            $donHang = DonHang::create([
                'MaKH' => $userId,
                'total_amount' => $totalAmount,
                'status' => 'Pending',
                'shipping_address' => $shippingInfo['address'],
                'shipping_phone' => $shippingInfo['phone'],
                'delivery_time' => $shippingInfo['delivery_time'] ?? null,
                'order_notes' => $shippingInfo['notes'] ?? null,
            ]);

            // Create order items and update inventory with FEFO strategy
            foreach ($cartItems as $item) {
                $remainingQty = $item['quantity'];
                $productId = $item['MaSP'];
                
                // Get batches using FEFO (First Expired, First Out)
                $cacLo = LoSanPham::where('MaSP', $productId)
                    ->inStock()
                    ->notExpired()
                    ->FEFO()
                    ->lockForUpdate()
                    ->get();
                
                if ($cacLo->sum('stock_quantity') < $remainingQty) {
                    $sanPham = SanPham::find($productId);
                    throw new \Exception("Sản phẩm {$sanPham->name} không đủ số lượng trong kho.");
                }
                
                // Deduct from batches following FEFO
                foreach ($cacLo as $lo) {
                    if ($remainingQty <= 0) break;
                    
                    $deductQty = min($remainingQty, $lo->stock_quantity);
                    
                    // Create order item for this batch
                    ChiTietDonHang::create([
                        'MaDH' => $donHang->MaDH,
                        'MaSP' => $productId,
                        'MaLo' => $lo->MaLo,
                        'quantity' => $deductQty,
                        'unit_price' => $sanPhams[$productId]->price_vnd,
                        'batch_number' => $lo->batch_number,
                    ]);
                    
                    // Deduct stock from batch
                    $lo->stock_quantity -= $deductQty;
                    $lo->save();
                    
                    $remainingQty -= $deductQty;
                }
                
                if ($remainingQty > 0) {
                    throw new \Exception("Không thể phân bổ đủ hàng cho sản phẩm.");
                }

                $updatedStock = (int) $cacLo->sum('stock_quantity');
                DB::table('products')
                    ->where('MaSP', $productId)
                    ->update([
                        'stock_quantity' => $updatedStock,
                        'updated_at' => now(),
                    ]);

                if (isset($sanPhams[$productId])) {
                    $sanPhams[$productId]->setAttribute('stock_quantity', $updatedStock);
                }
            }

            DB::commit();

            return $donHang;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Checkout transaction failed', [
                'MaKH' => $userId,
                'cart_items' => $cartItems,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new \Exception('Không thể hoàn tất đơn hàng. Vui lòng thử lại.');
        }
    }
}

