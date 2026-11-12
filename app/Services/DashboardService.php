<?php

namespace App\Services;

use App\Models\DonHang;
use App\Models\SanPham;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardService
{
    /**
     * Get total revenue for the current month
     */
    public function getMonthlyRevenue(): float
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        return DonHang::whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereIn('trang_thai', ['Delivering', 'Completed'])
            ->sum('tong_tien');
    }

    /**
     * Get order counts grouped by status
     */
    public function getOrderCountsByStatus(): array
    {
        $counts = DonHang::select('trang_thai', DB::raw('count(*) as count'))
            ->groupBy('trang_thai')
            ->pluck('count', 'trang_thai')
            ->toArray();

        // Ensure all statuses are present
        $statuses = ['Pending', 'Delivering', 'Completed', 'Cancelled'];
        $result = [];
        foreach ($statuses as $status) {
            $result[$status] = $counts[$status] ?? 0;
        }

        return $result;
    }

    /**
     * Get count of products expiring within specified days
     */
    public function getExpiringProductsCount(int $days = 30): int
    {
        $today = Carbon::today();
        $futureDate = Carbon::today()->addDays($days);

        return SanPham::whereBetween('ngay_het_han', [$today, $futureDate])
            ->where('so_luong_ton', '>', 0)
            ->count();
    }

    /**
     * Get count of low stock products
     */
    public function getLowStockCount(): int
    {
        return SanPham::whereColumn('so_luong_ton', '<=', 'muc_canh_bao')
            ->count();
    }

    /**
     * Get daily revenue data for chart (past N days)
     */
    public function getDailyRevenueChart(int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1)->startOfDay();
        $endDate = Carbon::today()->endOfDay();

        $revenues = DonHang::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(tong_tien) as revenue')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('trang_thai', ['Delivering', 'Completed'])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('revenue', 'date')
            ->toArray();

        // Fill in missing dates with 0
        $labels = [];
        $data = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i)->format('Y-m-d');
            $labels[] = Carbon::parse($date)->format('d/m');
            $data[] = $revenues[$date] ?? 0;
        }

        return [
            'labels' => $labels,
            'data' => $data
        ];
    }

    /**
     * Get order status distribution for pie chart
     */
    public function getOrderStatusDistribution(): array
    {
        $counts = $this->getOrderCountsByStatus();

        return [
            'labels' => array_keys($counts),
            'data' => array_values($counts)
        ];
    }

    /**
     * Get top selling products (by quantity sold)
     */
    public function getTopSellingProducts(int $limit = 10): array
    {
        $topProducts = DB::table('chi_tiet_don_hangs')
            ->join('don_hangs', 'chi_tiet_don_hangs.ma_dh', '=', 'don_hangs.ma_dh')
            ->join('san_phams', 'chi_tiet_don_hangs.ma_sp', '=', 'san_phams.ma_sp')
            ->whereIn('don_hangs.trang_thai', ['Delivering', 'Completed'])
            ->select(
                'san_phams.ten',
                DB::raw('SUM(chi_tiet_don_hangs.so_luong) as total_quantity')
            )
            ->groupBy('san_phams.ma_sp', 'san_phams.ten')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get();

        return [
            'labels' => $topProducts->pluck('ten')->toArray(),
            'data' => $topProducts->pluck('total_quantity')->toArray()
        ];
    }
}
