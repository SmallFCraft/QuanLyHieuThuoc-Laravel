<?php

namespace App\Http\Controllers\Admin;

use App\Exports\QuanTriTonKhoExport;
use App\Http\Controllers\Controller;
use App\Models\SanPham;
use App\Services\TonKhoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class TonKhoController extends Controller
{
    protected $tonKhoService;

    public function __construct(TonKhoService $tonKhoService)
    {
        $this->tonKhoService = $tonKhoService;
    }

    /**
     * Display inventory list with FEFO sorting and expiration highlighting.
     */
    public function index(Request $request)
    {
        $trangThai = $request->query('status');
        $soNgay = min(max($request->integer('days', 30), 1), 365);
        $tuKhoa = $request->filled('q') ? $request->string('q')->trim()->toString() : null;

        // Searching and filtering both run in SQL over the whole table, so the export
        // (which does the same) returns exactly the rows the screen shows. A
        // client-side DataTables search would only ever see the current page.
        $sanPhams = SanPham::with('danhMuc')
            ->inventoryStatus($trangThai, $soNgay)
            ->search($tuKhoa)
            ->FEFO()
            ->paginate(20)
            ->withQueryString();

        $tongHop = $this->tonKhoService->layTongQuanTonKho();

        return view('admin.inventory.index', compact('sanPhams', 'tongHop', 'trangThai', 'soNgay', 'tuKhoa'));
    }

    /**
     * Display low stock products.
     */
    public function lowStock()
    {
        return redirect()->route('admin.ton-kho.index', ['status' => 'low_stock']);
    }

    /**
     * Display products expiring soon.
     */
    public function expiring(Request $request)
    {
        $soNgay = min(max($request->integer('days', 30), 1), 365);

        return redirect()->route('admin.ton-kho.index', [
            'status' => 'expiring',
            'days' => $soNgay,
        ]);
    }

    public function export(Request $request)
    {
        $soNgay = min(max($request->integer('days', 30), 1), 365);

        return Excel::download(
            new QuanTriTonKhoExport(
                status: $request->filled('status') ? $request->string('status')->toString() : null,
                days: $soNgay,
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
            'MaSP' => 'required|exists:san_phams,ma_sp',
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
            $this->tonKhoService->dieuChinhTonKho(
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
