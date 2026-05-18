<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
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

        return Order::whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereIn('status', ['Delivering', 'Completed'])
            ->sum('total_amount');
    }

    /**
     * Get order counts grouped by status
     */
    public function getOrderCountsByStatus(): array
    {
        $counts = Order::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
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

        return Product::whereBetween('expiration_date', [$today, $futureDate])
            ->where('stock_quantity', '>', 0)
            ->count();
    }

    /**
     * Get count of low stock products
     */
    public function getLowStockCount(): int
    {
        return Product::whereColumn('stock_quantity', '<=', 'reorder_point')
            ->count();
    }

    /**
     * Get daily revenue data for chart (past N days)
     */
    public function getDailyRevenueChart(int $days = 30): array
    {
        $startDate = Carbon::today()->subDays($days - 1);
        $endDate = Carbon::today();

        $revenues = Order::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total_amount) as revenue')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['Delivering', 'Completed'])
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
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.MaDH', '=', 'orders.MaDH')
            ->join('products', 'order_items.MaSP', '=', 'products.MaSP')
            ->whereIn('orders.status', ['Delivering', 'Completed'])
            ->select(
                'products.name',
                DB::raw('SUM(order_items.quantity) as total_quantity')
            )
            ->groupBy('products.MaSP', 'products.name')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get();

        return [
            'labels' => $topProducts->pluck('name')->toArray(),
            'data' => $topProducts->pluck('total_quantity')->toArray()
        ];
    }
}
