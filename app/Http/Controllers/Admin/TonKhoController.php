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
     * Hiển thị danh sách tồn kho với sắp xếp FEFO và làm nổi bật ngày hết hạn.
     */
    public function index(Request $request)
    {
        $trangThai = $request->query('status');
        $soNgay = min(max($request->integer('days', 30), 1), 365);
        $tuKhoa = $request->filled('q') ? $request->string('q')->trim()->toString() : null;

        // Tìm kiếm và lọc đều chạy trong SQL trên cả bảng, nên file xuất (làm cùng một
        // điều đó) trả về đúng những dòng mà màn hình hiện. Một tìm kiếm DataTables
        // phía client chỉ bao giờ thấy trang hiện tại.
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
     * Hiển thị sản phẩm tồn kho thấp.
     */
    public function lowStock()
    {
        return redirect()->route('admin.ton-kho.index', ['status' => 'low_stock']);
    }

    /**
     * Hiển thị sản phẩm sắp hết hạn.
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
     * Xử lý điều chỉnh tồn kho.
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
