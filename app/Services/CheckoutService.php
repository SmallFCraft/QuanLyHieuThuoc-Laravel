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
        $productIds = array_column($cartItems, 'ma_sp');
        $sanPhams = SanPham::whereIn('ma_sp', $productIds)
            ->get()
            ->keyBy('ma_sp');
        
        // Quick validation BEFORE transaction (fail fast)
        $errors = [];
        $totalAmount = 0;
        
        foreach ($cartItems as $item) {
            $sanPham = $sanPhams[$item['ma_sp']] ?? null;
            
            if (!$sanPham) {
                $errors[] = "Sản phẩm không tồn tại.";
                continue;
            }
            
            // Check total stock across all batches
            $totalStock = LoSanPham::where('ma_sp', $sanPham->ma_sp)
                ->inStock()
                ->notExpired()
                ->sum('so_luong_ton');
            
            if ($totalStock < $item['quantity']) {
                $errors[] = "Sản phẩm {$sanPham->ten} không đủ số lượng. Còn lại: {$totalStock}.";
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
                'ma_nd' => $userId,
                'tong_tien' => $totalAmount,
                'trang_thai' => 'Pending',
                'dia_chi_giao_hang' => $shippingInfo['address'],
                'sdt_giao_hang' => $shippingInfo['phone'],
                'thoi_gian_giao' => $shippingInfo['delivery_time'] ?? null,
                'ghi_chu' => $shippingInfo['notes'] ?? null,
            ]);

            // Create order items and update inventory with FEFO strategy
            foreach ($cartItems as $item) {
                $remainingQty = $item['quantity'];
                $productId = $item['ma_sp'];
                
                // Get batches using FEFO (First Expired, First Out)
                $cacLo = LoSanPham::where('ma_sp', $productId)
                    ->inStock()
                    ->notExpired()
                    ->FEFO()
                    ->lockForUpdate()
                    ->get();
                
                if ($cacLo->sum('so_luong_ton') < $remainingQty) {
                    $sanPham = SanPham::find($productId);
                    throw new \Exception("Sản phẩm {$sanPham->ten} không đủ số lượng trong kho.");
                }
                
                // Deduct from batches following FEFO
                foreach ($cacLo as $lo) {
                    if ($remainingQty <= 0) break;
                    
                    $deductQty = min($remainingQty, $lo->so_luong_ton);
                    
                    // Create order item for this batch
                    ChiTietDonHang::create([
                        'ma_dh' => $donHang->ma_dh,
                        'ma_sp' => $productId,
                        'ma_lo' => $lo->ma_lo,
                        'so_luong' => $deductQty,
                        'don_gia' => $sanPhams[$productId]->price_vnd,
                        'so_lo' => $lo->so_lo,
                    ]);
                    
                    // Deduct stock from batch
                    $lo->so_luong_ton -= $deductQty;
                    $lo->save();
                    
                    $remainingQty -= $deductQty;
                }
                
                if ($remainingQty > 0) {
                    throw new \Exception("Không thể phân bổ đủ hàng cho sản phẩm.");
                }

                $updatedStock = (int) $cacLo->sum('so_luong_ton');
                DB::table('san_phams')
                    ->where('ma_sp', $productId)
                    ->update([
                        'so_luong_ton' => $updatedStock,
                        'updated_at' => now(),
                    ]);

                if (isset($sanPhams[$productId])) {
                    $sanPhams[$productId]->setAttribute('so_luong_ton', $updatedStock);
                }
            }

            DB::commit();

            return $donHang;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Checkout transaction failed', [
                'ma_nd' => $userId,
                'cart_items' => $cartItems,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new \Exception('Không thể hoàn tất đơn hàng. Vui lòng thử lại.');
        }
    }
}

