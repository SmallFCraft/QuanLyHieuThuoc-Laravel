<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $dashboardService;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00'));
        $this->dashboardService = app(DashboardService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_daily_revenue_chart_includes_orders_created_later_today(): void
    {
        $user = User::factory()->create();

        $this->createOrder($user, 'Completed', 125000, '2026-07-06 16:30:00');
        $this->createOrder($user, 'Cancelled', 999000, '2026-07-06 17:00:00');

        $chart = $this->dashboardService->getDailyRevenueChart(1);

        $this->assertSame(['06/07'], $chart['labels']);
        $this->assertEquals(125000.0, (float) $chart['data'][0]);
    }

    public function test_monthly_revenue_counts_delivering_and_completed_orders_only(): void
    {
        $user = User::factory()->create();

        foreach ([
            ['Completed', 100000, '2026-07-01 09:00:00'],
            ['Delivering', 50000, '2026-07-02 09:00:00'],
            ['Pending', 700000, '2026-07-03 09:00:00'],
            ['Cancelled', 900000, '2026-07-04 09:00:00'],
            ['Completed', 300000, '2026-06-30 09:00:00'],
        ] as [$status, $amount, $createdAt]) {
            $this->createOrder($user, $status, $amount, $createdAt);
        }

        $this->assertEquals(150000.0, $this->dashboardService->getMonthlyRevenue());
    }

    public function test_top_selling_products_excludes_pending_and_cancelled_orders(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Dashboard Test']);
        $product = Product::create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin Dashboard',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'DASHBOARD-001',
        ]);

        $completedOrder = $this->createOrder($user, 'Completed', 200000, '2026-07-06 10:00:00');
        $pendingOrder = $this->createOrder($user, 'Pending', 5000000, '2026-07-06 10:00:00');

        DB::table('order_items')->insert([
            [
                'MaDH' => $completedOrder->MaDH,
                'MaSP' => $product->MaSP,
                'quantity' => 4,
                'unit_price' => 50000,
                'batch_number' => 'DASHBOARD-001',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'MaDH' => $pendingOrder->MaDH,
                'MaSP' => $product->MaSP,
                'quantity' => 100,
                'unit_price' => 50000,
                'batch_number' => 'DASHBOARD-001',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $chart = $this->dashboardService->getTopSellingProducts(10);

        $this->assertSame(['Vitamin Dashboard'], $chart['labels']);
        $this->assertSame([4], array_map('intval', $chart['data']));
    }

    private function createOrder(User $user, string $status, int $amount, string $createdAt): Order
    {
        $timestamp = Carbon::parse($createdAt);
        $id = DB::table('orders')->insertGetId([
            'MaKH' => $user->MaKH,
            'total_amount' => $amount,
            'status' => $status,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Order::findOrFail($id);
    }
}
