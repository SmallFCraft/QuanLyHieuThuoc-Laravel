<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBatch;
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
     * @return Order
     * @throws \Exception
     */
    public function updateStatus(int $orderId, string $newStatus, int $userId): Order
    {
        return DB::transaction(function () use ($orderId, $newStatus, $userId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);

            if (!$this->validateStatusTransition($order->status, $newStatus)) {
                throw new \Exception("Không thể chuyển trạng thái từ {$order->status} sang {$newStatus}.");
            }

            $oldStatus = $order->status;
            $order->status = $newStatus;
            $order->save();

            Log::info('Order status updated', [
                'MaDH' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'MaKH' => $userId
            ]);

            // Idempotent (updateOrCreate on MaDH + MaSP), so a replayed status
            // change cannot duplicate a patient's medication schedule.
            if ($newStatus === 'Completed' && $oldStatus !== 'Completed') {
                app(MedicationReminderService::class)->createFromOrder($order);
            }

            return $order;
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
            $order = Order::with('items')->lockForUpdate()->findOrFail($orderId);

            // Only pending orders can be cancelled
            if ($order->status !== 'Pending') {
                throw new \Exception('Chỉ có thể hủy đơn hàng ở trạng thái Pending.');
            }

            $affectedProductIds = [];

            // Restore inventory for all order items (by batch)
            foreach ($order->items as $item) {
                $affectedProductIds[] = $item->MaSP;

                if ($item->MaLo) {
                    // Restore to specific batch
                    $batch = ProductBatch::lockForUpdate()->find($item->MaLo);
                    if ($batch) {
                        $batch->stock_quantity += $item->quantity;
                        $batch->save();
                    }
                } else {
                    // Không có MaLo: ưu tiên lô trùng batch_number (ProductObserver tạo lô khi tạo SP)
                    $batch = ProductBatch::query()
                        ->where('MaSP', $item->MaSP)
                        ->where('batch_number', $item->batch_number)
                        ->lockForUpdate()
                        ->first();
                    if ($batch) {
                        $batch->stock_quantity += $item->quantity;
                        $batch->save();
                    } else {
                        $product = Product::lockForUpdate()->find($item->MaSP);
                        if ($product) {
                            $product->stock_quantity += $item->quantity;
                            $product->save();
                        }
                    }
                }
            }

            foreach (array_unique($affectedProductIds) as $productId) {
                $product = Product::with('batches')->lockForUpdate()->find($productId);

                if (!$product) {
                    continue;
                }

                $updatedStock = $product->batches->isNotEmpty()
                    ? (int) $product->batches->sum('stock_quantity')
                    : (int) $product->stock_quantity;

                $product->stock_quantity = $updatedStock;
                $product->save();
            }

            // Update order status
            $order->status = 'Cancelled';
            $order->save();

            DB::commit();

            Log::info('Order cancelled and inventory restored', [
                'MaDH' => $orderId,
                'MaKH' => $userId
            ]);

            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Order cancellation failed', [
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
