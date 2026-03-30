<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Display the admin dashboard
     */
    public function index()
    {
        $monthlyRevenue = $this->dashboardService->getMonthlyRevenue();
        $orderCounts = $this->dashboardService->getOrderCountsByStatus();
        $expiringProductsCount = $this->dashboardService->getExpiringProductsCount(30);
        $lowStockCount = $this->dashboardService->getLowStockCount();

        return view('admin.dashboard', compact(
            'monthlyRevenue',
            'orderCounts',
            'expiringProductsCount',
            'lowStockCount'
        ));
    }

    /**
     * Get revenue chart data (AJAX endpoint)
     */
    public function revenueChart(Request $request)
    {
        $days = $request->input('days', 30);
        $chartData = $this->dashboardService->getDailyRevenueChart($days);

        return response()->json($chartData);
    }

    /**
     * Get order status chart data (AJAX endpoint)
     */
    public function orderStatusChart()
    {
        $chartData = $this->dashboardService->getOrderStatusDistribution();

        return response()->json($chartData);
    }

    /**
     * Get top products chart data (AJAX endpoint)
     */
    public function topProductsChart(Request $request)
    {
        $limit = $request->input('limit', 10);
        $chartData = $this->dashboardService->getTopSellingProducts($limit);

        return response()->json($chartData);
    }
}
