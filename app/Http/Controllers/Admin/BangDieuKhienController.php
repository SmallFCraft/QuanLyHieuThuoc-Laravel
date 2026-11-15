<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BangDieuKhienService;
use Illuminate\Http\Request;

class BangDieuKhienController extends Controller
{
    protected $bangDieuKhienService;

    public function __construct(BangDieuKhienService $bangDieuKhienService)
    {
        $this->bangDieuKhienService = $bangDieuKhienService;
    }

    /**
     * Hiển thị bảng điều khiển quản trị
     */
    public function index()
    {
        $doanhThuThang = $this->bangDieuKhienService->layDoanhThuThang();
        $soDonTheoTrangThai = $this->bangDieuKhienService->laySoDonTheoTrangThai();
        $soSanPhamSapHetHan = $this->bangDieuKhienService->laySoSanPhamSapHetHan(30);
        $soSanPhamSapHet = $this->bangDieuKhienService->laySoSanPhamSapHet();

        return view('admin.dashboard', compact(
            'doanhThuThang',
            'soDonTheoTrangThai',
            'soSanPhamSapHetHan',
            'soSanPhamSapHet'
        ));
    }

    /**
     * Lấy dữ liệu biểu đồ doanh thu (AJAX endpoint)
     */
    public function revenueChart(Request $request)
    {
        $days = min(max($request->integer('days', 30), 1), 365);
        $chartData = $this->bangDieuKhienService->layBieuDoDoanhThuNgay($days);

        return response()->json($chartData);
    }

    /**
     * Lấy dữ liệu biểu đồ trạng thái đơn hàng (AJAX endpoint)
     */
    public function orderStatusChart()
    {
        $chartData = $this->bangDieuKhienService->layPhanBoTrangThaiDon();

        return response()->json($chartData);
    }

    /**
     * Lấy dữ liệu biểu đồ sản phẩm bán chạy (AJAX endpoint)
     */
    public function topProductsChart(Request $request)
    {
        $limit = min(max($request->integer('limit', 10), 1), 50);
        $chartData = $this->bangDieuKhienService->laySanPhamBanChay($limit);

        return response()->json($chartData);
    }
}
