<?php

namespace App\Http\Controllers\Admin;

use App\Exports\AdminInventoryExport;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class InventoryController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Display inventory list with FEFO sorting and expiration highlighting.
     */
    public function index(Request $request)
    {
        $status = $request->query('status');
        $days = min(max($request->integer('days', 30), 1), 365);
        $query = Product::with('category');

        match ($status) {
            'low_stock' => $query->lowStock(),
            'expiring' => $query->expiringSoon($days),
            'expired' => $query->where('expiration_date', '<', now()),
            'out_of_stock' => $query->where('stock_quantity', 0),
            default => null,
        };

        $products = $query->FEFO()
            ->paginate(20)
            ->withQueryString();
        
        $summary = $this->inventoryService->getInventorySummary();
        
        return view('admin.inventory.index', compact('products', 'summary', 'status', 'days'));
    }

    /**
     * Display low stock products.
     */
    public function lowStock()
    {
        return redirect()->route('admin.inventory.index', ['status' => 'low_stock']);
    }

    /**
     * Display products expiring soon.
     */
    public function expiring(Request $request)
    {
        $days = min(max($request->integer('days', 30), 1), 365);

        return redirect()->route('admin.inventory.index', [
            'status' => 'expiring',
            'days' => $days,
        ]);
    }

    public function export(Request $request)
    {
        $days = min(max($request->integer('days', 30), 1), 365);

        return Excel::download(
            new AdminInventoryExport(
                status: $request->filled('status') ? $request->string('status')->toString() : null,
                days: $days,
                search: $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            ),
            'ton-kho.xlsx'
        );
    }

    /**
     * Process inventory adjustment.
     */
    public function adjust(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'MaSP' => 'required|exists:products,MaSP',
            'adjustment' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:255',
        ], [
            'MaSP.required' => 'Sản phẩm là bắt buộc',
            'MaSP.exists' => 'Sản phẩm không tồn tại',
            'adjustment.required' => 'Số lượng điều chỉnh là bắt buộc',
            'adjustment.integer' => 'Số lượng điều chỉnh phải là số nguyên',
            'adjustment.not_in' => 'Số lượng điều chỉnh không thể bằng 0',
            'reason.required' => 'Lý do điều chỉnh là bắt buộc',
            'reason.max' => 'Lý do không được vượt quá 255 ký tự',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $this->inventoryService->adjustStock(
                productId: $request->MaSP,
                quantity: $request->adjustment,
                reason: $request->reason,
                userId: auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Điều chỉnh tồn kho thành công'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

}
