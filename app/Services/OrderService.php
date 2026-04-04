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
        $order = Order::findOrFail($orderId);

        if (!$this->validateStatusTransition($order->status, $newStatus)) {
            throw new \Exception("Không thể chuyển trạng thái từ {$order->status} sang {$newStatus}.");
        }

        $oldStatus = $order->status;
        $order->status = $newStatus;
        $order->save();

        Log::info('Order status updated', [
            'order_id' => $orderId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'user_id' => $userId
        ]);

        return $order;
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
        $order = Order::with('items')->findOrFail($orderId);

        // Only pending orders can be cancelled
        if ($order->status !== 'Pending') {
            throw new \Exception('Chỉ có thể hủy đơn hàng ở trạng thái Pending.');
        }

        DB::beginTransaction();

        try {
            $affectedProductIds = [];

            // Restore inventory for all order items (by batch)
            foreach ($order->items as $item) {
                $affectedProductIds[] = $item->product_id;

                if ($item->batch_id) {
                    // Restore to specific batch
                    $batch = ProductBatch::lockForUpdate()->find($item->batch_id);
                    if ($batch) {
                        $batch->stock_quantity += $item->quantity;
                        $batch->save();
                    }
                } else {
                    // Không có batch_id: ưu tiên lô trùng batch_number (ProductObserver tạo lô khi tạo SP)
                    $batch = ProductBatch::query()
                        ->where('product_id', $item->product_id)
                        ->where('batch_number', $item->batch_number)
                        ->lockForUpdate()
                        ->first();
                    if ($batch) {
                        $batch->stock_quantity += $item->quantity;
                        $batch->save();
                    } else {
                        $product = Product::lockForUpdate()->find($item->product_id);
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
                'order_id' => $orderId,
                'user_id' => $userId
            ]);

            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Order cancellation failed', [
                'order_id' => $orderId,
                'user_id' => $userId,
                'exception' => $e->getMessage()
            ]);
            
            throw new \Exception('Không thể hủy đơn hàng. Vui lòng thử lại.');
        }
    }

    /**
     * Get orders by status.
     *
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getOrdersByStatus(string $status)
    {
        return Order::with(['user', 'items.product'])
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->get();
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
