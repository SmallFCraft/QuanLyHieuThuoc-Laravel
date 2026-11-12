<?php

namespace Tests\Unit;

use App\Models\DanhMuc;
use App\Models\DonHang;
use App\Models\SanPham;
use App\Models\NguoiDung;
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
        $nguoiDung = NguoiDung::factory()->create();

        $this->createOrder($nguoiDung, 'Completed', 125000, '2026-07-06 16:30:00');
        $this->createOrder($nguoiDung, 'Cancelled', 999000, '2026-07-06 17:00:00');

        $chart = $this->dashboardService->getDailyRevenueChart(1);

        $this->assertSame(['06/07'], $chart['labels']);
        $this->assertEquals(125000.0, (float) $chart['data'][0]);
    }

    public function test_monthly_revenue_counts_delivering_and_completed_orders_only(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        foreach ([
            ['Completed', 100000, '2026-07-01 09:00:00'],
            ['Delivering', 50000, '2026-07-02 09:00:00'],
            ['Pending', 700000, '2026-07-03 09:00:00'],
            ['Cancelled', 900000, '2026-07-04 09:00:00'],
            ['Completed', 300000, '2026-06-30 09:00:00'],
        ] as [$status, $amount, $createdAt]) {
            $this->createOrder($nguoiDung, $status, $amount, $createdAt);
        }

        $this->assertEquals(150000.0, $this->dashboardService->getMonthlyRevenue());
    }

    public function test_top_selling_products_excludes_pending_and_cancelled_orders(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $danhMuc = DanhMuc::create(['name' => 'Dashboard Test']);
        $sanPham = SanPham::create([
            'MaDM' => $danhMuc->MaDM,
            'name' => 'Vitamin Dashboard',
            'price' => 50000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'DASHBOARD-001',
        ]);

        $completedOrder = $this->createOrder($nguoiDung, 'Completed', 200000, '2026-07-06 10:00:00');
        $pendingOrder = $this->createOrder($nguoiDung, 'Pending', 5000000, '2026-07-06 10:00:00');

        DB::table('order_items')->insert([
            [
                'MaDH' => $completedOrder->MaDH,
                'MaSP' => $sanPham->MaSP,
                'quantity' => 4,
                'unit_price' => 50000,
                'batch_number' => 'DASHBOARD-001',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'MaDH' => $pendingOrder->MaDH,
                'MaSP' => $sanPham->MaSP,
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

    private function createOrder(NguoiDung $nguoiDung, string $status, int $amount, string $createdAt): DonHang
    {
        $timestamp = Carbon::parse($createdAt);
        $id = DB::table('orders')->insertGetId([
            'MaKH' => $nguoiDung->MaKH,
            'total_amount' => $amount,
            'status' => $status,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return DonHang::findOrFail($id);
    }
}
