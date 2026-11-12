<?php

namespace App\Services;

use App\Models\DonHang;
use App\Models\SanPham;
use App\Models\LoSanPham;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
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
    public function updateStatus(int $orderId, string $newStatus, int $userId): DonHang
    {
        return DB::transaction(function () use ($orderId, $newStatus, $userId) {
            $donHang = DonHang::lockForUpdate()->findOrFail($orderId);

            if (!$this->validateStatusTransition($donHang->trang_thai, $newStatus)) {
                throw new \Exception("Không thể chuyển trạng thái từ {$donHang->trang_thai} sang {$newStatus}.");
            }

            $oldStatus = $donHang->trang_thai;
            $donHang->trang_thai = $newStatus;
            $donHang->save();

            Log::info('DonHang status updated', [
                'ma_dh' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'ma_nd' => $userId
            ]);

            // Idempotent (updateOrCreate on ma_dh + ma_sp), so a replayed status
            // change cannot duplicate a patient's medication schedule.
            if ($newStatus === 'Completed' && $oldStatus !== 'Completed') {
                app(MedicationReminderService::class)->createFromOrder($donHang);
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
    public function cancelOrder(int $orderId, int $userId): bool
    {
        DB::beginTransaction();

        try {
            $donHang = DonHang::with('chiTietDonHangs')->lockForUpdate()->findOrFail($orderId);

            // Only pending orders can be cancelled
            if ($donHang->trang_thai !== 'Pending') {
                throw new \Exception('Chỉ có thể hủy đơn hàng ở trạng thái Pending.');
            }

            $affectedProductIds = [];

            // Restore inventory for all order items (by batch)
            foreach ($donHang->chiTietDonHangs as $item) {
                $affectedProductIds[] = $item->ma_sp;

                if ($item->ma_lo) {
                    // Restore to specific batch
                    $lo = LoSanPham::lockForUpdate()->find($item->ma_lo);
                    if ($lo) {
                        $lo->so_luong_ton += $item->so_luong;
                        $lo->save();
                    }
                } else {
                    // Không có ma_lo: ưu tiên lô trùng số lô (SanPhamObserver tạo lô khi tạo SP)
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

            foreach (array_unique($affectedProductIds) as $productId) {
                $sanPham = SanPham::with('cacLo')->lockForUpdate()->find($productId);

                if (!$sanPham) {
                    continue;
                }

                $updatedStock = $sanPham->cacLo->isNotEmpty()
                    ? (int) $sanPham->cacLo->sum('so_luong_ton')
                    : (int) $sanPham->so_luong_ton;

                $sanPham->so_luong_ton = $updatedStock;
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
    public function validateStatusTransition(string $currentStatus, string $newStatus): bool
    {
        $validTransitions = [
            'Pending' => ['Delivering', 'Cancelled'],
            'Delivering' => ['Completed'],
            'Completed' => [],
            'Cancelled' => []
        ];

        return in_array($newStatus, $validTransitions[$currentStatus] ?? []);
    }
}
