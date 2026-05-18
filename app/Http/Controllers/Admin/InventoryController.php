<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
    public function index()
    {
        $products = Product::with('category')
            ->FEFO()
            ->paginate(20);
        
        $summary = $this->inventoryService->getInventorySummary();
        
        return view('admin.inventory.index', compact('products', 'summary'));
    }

    /**
     * Display low stock products.
     */
    public function lowStock()
    {
        $products = $this->inventoryService->getLowStockProducts();
        
        return view('admin.inventory.low-stock', compact('products'));
    }

    /**
     * Display products expiring soon.
     */
    public function expiring(Request $request)
    {
        $days = min(max($request->integer('days', 30), 1), 365);
        $products = $this->inventoryService->checkExpiration($days);
        
        return view('admin.inventory.expiring', compact('products', 'days'));
    }

    /**
     * Show the inventory adjustment form.
     */
    public function adjustForm(Product $product)
    {
        return view('admin.inventory.adjust', compact('product'));
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

    /**
     * Get inventory data for DataTables.
     */
    public function data(Request $request)
    {
        $query = Product::with('category');
        
        // Filter by status
        if ($request->has('status')) {
            switch ($request->status) {
                case 'expired':
                    $query->where('expiration_date', '<', now());
                    break;
                case 'expiring':
                    $query->expiringSoon(30);
                    break;
                case 'low_stock':
                    $query->lowStock();
                    break;
                case 'out_of_stock':
                    $query->where('stock_quantity', 0);
                    break;
            }
        }
        
        // Search
        if ($request->has('search') && $request->search['value']) {
            $search = $request->search['value'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('batch_number', 'like', "%{$search}%");
            });
        }
        
        // Total records
        $totalRecords = Product::count();
        $filteredRecords = $query->count();
        
        // FEFO sorting by default
        $query->FEFO();
        
        // Pagination
        $start = max(0, $request->integer('start', 0));
        $length = min(max($request->integer('length', 20), 1), 100);
        $products = $query->skip($start)->take($length)->get();
        
        // Format data
        $data = $products->map(function($product) {
            $statusClass = '';
            $statusText = '';
            
            if ($product->isExpired()) {
                $statusClass = 'danger';
                $statusText = 'Đã hết hạn';
            } elseif ($product->isExpiringSoon(30)) {
                $statusClass = 'warning';
                $statusText = 'Sắp hết hạn';
            } elseif ($product->isLowStock()) {
                $statusClass = 'info';
                $statusText = 'Tồn kho thấp';
            } else {
                $statusClass = 'success';
                $statusText = 'Bình thường';
            }
            
            return [
                'MaSP' => $product->MaSP,
                'name' => $product->name,
                'category' => $product->category->name,
                'batch_number' => $product->batch_number,
                'stock_quantity' => $product->stock_quantity,
                'reorder_point' => $product->reorder_point,
                'expiration_date' => $product->formatted_expiration_date,
                'status' => [
                    'class' => $statusClass,
                    'text' => $statusText
                ],
                'actions' => view('admin.inventory.partials.actions', compact('product'))->render()
            ];
        });
        
        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $data
        ]);
    }
}
