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

            if (!$this->validateStatusTransition($donHang->status, $newStatus)) {
                throw new \Exception("Không thể chuyển trạng thái từ {$donHang->status} sang {$newStatus}.");
            }

            $oldStatus = $donHang->status;
            $donHang->status = $newStatus;
            $donHang->save();

            Log::info('DonHang status updated', [
                'MaDH' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'MaKH' => $userId
            ]);

            // Idempotent (updateOrCreate on MaDH + MaSP), so a replayed status
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
            if ($donHang->status !== 'Pending') {
                throw new \Exception('Chỉ có thể hủy đơn hàng ở trạng thái Pending.');
            }

            $affectedProductIds = [];

            // Restore inventory for all order items (by batch)
            foreach ($donHang->chiTietDonHangs as $item) {
                $affectedProductIds[] = $item->MaSP;

                if ($item->MaLo) {
                    // Restore to specific batch
                    $lo = LoSanPham::lockForUpdate()->find($item->MaLo);
                    if ($lo) {
                        $lo->stock_quantity += $item->quantity;
                        $lo->save();
                    }
                } else {
                    // Không có MaLo: ưu tiên lô trùng batch_number (ProductObserver tạo lô khi tạo SP)
                    $lo = LoSanPham::query()
                        ->where('MaSP', $item->MaSP)
                        ->where('batch_number', $item->batch_number)
                        ->lockForUpdate()
                        ->first();
                    if ($lo) {
                        $lo->stock_quantity += $item->quantity;
                        $lo->save();
                    } else {
                        $sanPham = SanPham::lockForUpdate()->find($item->MaSP);
                        if ($sanPham) {
                            $sanPham->stock_quantity += $item->quantity;
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
                    ? (int) $sanPham->cacLo->sum('stock_quantity')
                    : (int) $sanPham->stock_quantity;

                $sanPham->stock_quantity = $updatedStock;
                $sanPham->save();
            }

            // Update order status
            $donHang->status = 'Cancelled';
            $donHang->save();

            DB::commit();

            Log::info('DonHang cancelled and inventory restored', [
                'MaDH' => $orderId,
                'MaKH' => $userId
            ]);

            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('DonHang cancellation failed', [
                'MaDH' => $orderId,
                'MaKH' => $userId,
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
