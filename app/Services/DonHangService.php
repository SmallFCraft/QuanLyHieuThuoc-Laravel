<?php

namespace App\Services;

use App\Models\DonHang;
use App\Models\SanPham;
use App\Models\LoSanPham;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DonHangService
{
    /**
     * Update order status with validation.
     *
     * @param int $orderId
     * @param string $newStatus
     * @param int $userId
     * @return DonHang
     * @throws \Exception
     */
    public function capNhatTrangThai(int $orderId, string $newStatus, int $userId): DonHang
    {
        return DB::transaction(function () use ($orderId, $newStatus, $userId) {
            $donHang = DonHang::lockForUpdate()->findOrFail($orderId);

            if (!$this->kiemTraChuyenTrangThai($donHang->trang_thai, $newStatus)) {
                throw new \Exception("Không thể chuyển trạng thái từ {$donHang->trang_thai} sang {$newStatus}.");
            }

            $trangThaiCu = $donHang->trang_thai;
            $donHang->trang_thai = $newStatus;
            $donHang->save();

            Log::info('DonHang status updated', [
                'ma_dh' => $orderId,
                'old_status' => $trangThaiCu,
                'new_status' => $newStatus,
                'ma_nd' => $userId
            ]);

            // Idempotent (updateOrCreate on ma_dh + ma_sp), so a replayed status
            // change cannot duplicate a patient's medication schedule.
            if ($newStatus === 'Completed' && $trangThaiCu !== 'Completed') {
                app(NhacUongThuocService::class)->taoTuDonHang($donHang);
            }

            return $donHang;
        });
    }

    /**
     * Cancel an order and restore inventory.
     *
     * @param int $orderId
     * @param int $userId
     * @return bool
     * @throws \Exception
     */
    public function huyDonHang(int $orderId, int $userId): bool
    {
        DB::beginTransaction();

        try {
            $donHang = DonHang::with('chiTietDonHangs')->lockForUpdate()->findOrFail($orderId);

            // Only pending orders can be cancelled
            if ($donHang->trang_thai !== 'Pending') {
                throw new \Exception('Chỉ có thể hủy đơn hàng ở trạng thái Pending.');
            }

            $cacMaSanPhamAnhHuong = [];

            // Restore inventory for all order items (by batch)
            foreach ($donHang->chiTietDonHangs as $item) {
                $cacMaSanPhamAnhHuong[] = $item->ma_sp;

                if ($item->ma_lo) {
                    // Restore to specific batch
                    $lo = LoSanPham::lockForUpdate()->find($item->ma_lo);
                    if ($lo) {
                        $lo->so_luong_ton += $item->so_luong;
                        $lo->save();
                    }
                } else {
                    // Khong co ma_lo: uu tien lo trung so lo (SanPhamObserver tao lo khi tao SP)
                    $lo = LoSanPham::query()
                        ->where('ma_sp', $item->ma_sp)
                        ->where('so_lo', $item->so_lo)
                        ->lockForUpdate()
                        ->first();
                    if ($lo) {
                        $lo->so_luong_ton += $item->so_luong;
                        $lo->save();
                    } else {
                        $sanPham = SanPham::lockForUpdate()->find($item->ma_sp);
                        if ($sanPham) {
                            $sanPham->so_luong_ton += $item->so_luong;
                            $sanPham->save();
                        }
                    }
                }
            }

            foreach (array_unique($cacMaSanPhamAnhHuong) as $productId) {
                $sanPham = SanPham::with('cacLo')->lockForUpdate()->find($productId);

                if (!$sanPham) {
                    continue;
                }

                $tonKhoCapNhat = $sanPham->cacLo->isNotEmpty()
                    ? (int) $sanPham->cacLo->sum('so_luong_ton')
                    : (int) $sanPham->so_luong_ton;

                $sanPham->so_luong_ton = $tonKhoCapNhat;
                $sanPham->save();
            }

            // Update order status
            $donHang->trang_thai = 'Cancelled';
            $donHang->save();

            DB::commit();

            Log::info('DonHang cancelled and inventory restored', [
                'ma_dh' => $orderId,
                'ma_nd' => $userId
            ]);

            return true;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('DonHang cancellation failed', [
                'ma_dh' => $orderId,
                'ma_nd' => $userId,
                'exception' => $e->getMessage()
            ]);

            throw new \Exception('Không thể hủy đơn hàng. Vui lòng thử lại.');
        }
    }

    /**
     * Validate if a status transition is allowed.
     *
     * @param string $currentStatus
     * @param string $newStatus
     * @return bool
     */
    public function kiemTraChuyenTrangThai(string $currentStatus, string $newStatus): bool
    {
        $cacChuyenTrangThaiHopLe = [
            'Pending' => ['Delivering', 'Cancelled'],
            'Delivering' => ['Completed'],
            'Completed' => [],
            'Cancelled' => []
        ];

        return in_array($newStatus, $cacChuyenTrangThaiHopLe[$currentStatus] ?? []);
    }
}
