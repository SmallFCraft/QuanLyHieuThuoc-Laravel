<?php

namespace App\Http\Controllers\Admin;

use App\Exports\QuanTriDonHangExport;
use App\Http\Controllers\Controller;
use App\Models\DonHang;
use App\Services\DonHangService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class DonHangController extends Controller
{
    protected $donHangService;

    public function __construct(DonHangService $donHangService)
    {
        $this->donHangService = $donHangService;
    }

    /**
     * Display a listing of orders.
     */
    public function index(Request $request)
    {
        $query = DonHang::with(['nguoiDung', 'chiTietDonHangs']);

        // Filter by status if provided
        if ($request->has('status') && $request->status) {
            $query->where('trang_thai', $request->status);
        }

        $donHangs = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('admin.orders.index', compact('donHangs'));
    }

    /**
     * Display the specified order.
     */
    public function show($MaDH)
    {
        $donHang = DonHang::with(['nguoiDung', 'chiTietDonHangs.sanPham'])->findOrFail($MaDH);

        return view('admin.orders.show', compact('donHang'));
    }

    public function export(Request $request)
    {
        return Excel::download(
            new QuanTriDonHangExport(
                status: $request->filled('status') ? $request->string('status')->toString() : null,
                search: $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            ),
            'don-hang.xlsx'
        );
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
            $result = $this->donHangService->capNhatTrangThai(
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
            $this->donHangService->huyDonHang($MaDH, auth()->id());

            return back()->with('success', 'Đã hủy đơn hàng và hoàn trả tồn kho.');
            
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
