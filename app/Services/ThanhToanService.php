<?php

namespace App\Services;

use App\Models\DonHang;
use App\Models\ChiTietDonHang;
use App\Models\SanPham;
use App\Models\LoSanPham;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ThanhToanService
{
    /**
     * Xử lý checkout với an toàn transaction.
     *
     * @param array $cartItems
     * @param int $userId
     * @param array $shippingInfo
     * @return DonHang
     * @throws \Exception
     */
    public function xuLyThanhToan(array $cartItems, int $userId, array $shippingInfo): DonHang
    {
        // TỐI ƯU: Tải mỗi sản phẩm trong 1 truy vấn thay vì N+1
        $cacMaSanPham = array_column($cartItems, 'ma_sp');
        $sanPhams = SanPham::whereIn('ma_sp', $cacMaSanPham)
            ->get()
            ->keyBy('ma_sp');

        // Kiểm tra nhanh TRƯỚC transaction (fail fast)
        $cacLoi = [];
        $tongTien = 0;

        foreach ($cartItems as $item) {
            $sanPham = $sanPhams[$item['ma_sp']] ?? null;

            if (!$sanPham) {
                $cacLoi[] = "Sản phẩm không tồn tại.";
                continue;
            }

            // Kiểm tra tổng tồn kho trên mỗi lô
            $tongTon = LoSanPham::where('ma_sp', $sanPham->ma_sp)
                ->inStock()
                ->notExpired()
                ->sum('so_luong_ton');

            if ($tongTon < $item['quantity']) {
                $cacLoi[] = "Sản phẩm {$sanPham->ten} không đủ số lượng. Còn lại: {$tongTon}.";
            }

            $tongTien += $sanPham->price_vnd * $item['quantity'];
        }

        if (!empty($cacLoi)) {
            throw new \Exception(implode(' ', $cacLoi));
        }

        DB::beginTransaction();

        try {
            // Tạo đơn hàng
            $donHang = DonHang::create([
                'ma_nd' => $userId,
                'tong_tien' => $tongTien,
                'trang_thai' => 'Pending',
                'dia_chi_giao_hang' => $shippingInfo['address'],
                'sdt_giao_hang' => $shippingInfo['phone'],
                'thoi_gian_giao' => $shippingInfo['delivery_time'] ?? null,
                'ghi_chu' => $shippingInfo['notes'] ?? null,
            ]);

            // Tạo chi tiết đơn hàng và cập nhật tồn kho theo chiến lược FEFO
            foreach ($cartItems as $item) {
                $soLuongCon = $item['quantity'];
                $maSanPham = $item['ma_sp'];

                // Lấy các lô theo FEFO (First Expired, First Out)
                $cacLo = LoSanPham::where('ma_sp', $maSanPham)
                    ->inStock()
                    ->notExpired()
                    ->FEFO()
                    ->lockForUpdate()
                    ->get();

                if ($cacLo->sum('so_luong_ton') < $soLuongCon) {
                    $sanPham = SanPham::find($maSanPham);
                    throw new \Exception("Sản phẩm {$sanPham->ten} không đủ số lượng trong kho.");
                }

                // Trừ từ các lô theo FEFO
                foreach ($cacLo as $lo) {
                    if ($soLuongCon <= 0) break;

                    $soLuongTru = min($soLuongCon, $lo->so_luong_ton);

                    // Tạo chi tiết đơn hàng cho lô này
                    ChiTietDonHang::create([
                        'ma_dh' => $donHang->ma_dh,
                        'ma_sp' => $maSanPham,
                        'ma_lo' => $lo->ma_lo,
                        'so_luong' => $soLuongTru,
                        'don_gia' => $sanPhams[$maSanPham]->price_vnd,
                        'so_lo' => $lo->so_lo,
                    ]);

                    // Trừ tồn kho từ lô
                    $lo->so_luong_ton -= $soLuongTru;
                    $lo->save();

                    $soLuongCon -= $soLuongTru;
                }

                if ($soLuongCon > 0) {
                    throw new \Exception("Không thể phân bổ đủ hàng cho sản phẩm.");
                }

                $tonKhoCapNhat = (int) $cacLo->sum('so_luong_ton');
                DB::table('san_phams')
                    ->where('ma_sp', $maSanPham)
                    ->update([
                        'so_luong_ton' => $tonKhoCapNhat,
                        'updated_at' => now(),
                    ]);

                if (isset($sanPhams[$maSanPham])) {
                    $sanPhams[$maSanPham]->setAttribute('so_luong_ton', $tonKhoCapNhat);
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