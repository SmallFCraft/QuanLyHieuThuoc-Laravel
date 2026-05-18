<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    protected $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Display a listing of orders.
     */
    public function index(Request $request)
    {
        $query = Order::with(['user', 'items']);

        // Filter by status if provided
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('admin.orders.index', compact('orders'));
    }

    /**
     * Display the specified order.
     */
    public function show($MaDH)
    {
        $order = Order::with(['user', 'items.product'])->findOrFail($MaDH);

        return view('admin.orders.show', compact('order'));
    }

    /**
     * Update the order status.
     */
    public function updateStatus(Request $request, $MaDH)
    {
        $request->validate([
            'status' => 'required|in:Pending,Delivering,Completed,Cancelled'
        ]);

        try {
            $order = $this->orderService->updateStatus(
                $MaDH,
                $request->status,
                auth()->id()
            );

            return back()->with('success', 'Đã cập nhật trạng thái đơn hàng.');
            
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel the order.
     */
    public function cancel($MaDH)
    {
        try {
            $this->orderService->cancelOrder($MaDH, auth()->id());

            return back()->with('success', 'Đã hủy đơn hàng và hoàn trả tồn kho.');
            
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
