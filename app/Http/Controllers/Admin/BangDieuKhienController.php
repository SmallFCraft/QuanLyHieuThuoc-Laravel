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
     * Display the admin dashboard
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
     * Get revenue chart data (AJAX endpoint)
     */
    public function revenueChart(Request $request)
    {
        $days = min(max($request->integer('days', 30), 1), 365);
        $chartData = $this->bangDieuKhienService->layBieuDoDoanhThuNgay($days);

        return response()->json($chartData);
    }

    /**
     * Get order status chart data (AJAX endpoint)
     */
    public function orderStatusChart()
    {
        $chartData = $this->bangDieuKhienService->layPhanBoTrangThaiDon();

        return response()->json($chartData);
    }

    /**
     * Get top products chart data (AJAX endpoint)
     */
    public function topProductsChart(Request $request)
    {
        $limit = min(max($request->integer('limit', 10), 1), 50);
        $chartData = $this->bangDieuKhienService->laySanPhamBanChay($limit);

        return response()->json($chartData);
    }
}
