<?php

namespace Tests\Feature;

use App\Models\NguoiDung;
use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\DonHang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoadTestingTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $nguoiDung;
    protected $danhMuc;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator',
            'trang_thai' => 'active'
        ]);
        
        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active'
        ]);
        
        $this->danhMuc = DanhMuc::create(['ten' => 'Test Category',
            'mo_ta' => 'Test Description'
        ]);

        // Create test products
        for ($i = 1; $i <= 50; $i++) {
            SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
                'ten' => "Product {$i}",
                'gia' => 10000 * $i,
                'ngay_het_han' => now()->addYear(),
                'so_luong_ton' => 100,
                'muc_canh_bao' => 10,
                'so_lo' => "BATCH-LOAD-{$i}"
            ]);
        }
    }

    public function test_product_catalog_page_load_time_under_one_second(): void
    {
        $startTime = microtime(true);
        
        $response = $this->get(route('products.index'));
        
        $endTime = microtime(true);
        $loadTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $response->assertStatus(200);
        
        // Page should load in less than 1000ms (1 second)
        $this->assertLessThan(1000, $loadTime,
            "Product catalog loaded in {$loadTime}ms, expected < 1000ms");
    }

    public function test_dashboard_page_load_time_under_two_seconds(): void
    {
        // Create some orders for dashboard metrics
        for ($i = 1; $i <= 20; $i++) {
            DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
                'tong_tien' => 50000,
                'trang_thai' => ['Pending', 'Delivering', 'Completed', 'Cancelled'][rand(0, 3)],
                'dia_chi_giao_hang' => '123 Test Street',
                'sdt_giao_hang' => '0901234567'
            ]);
        }

        $startTime = microtime(true);
        
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'));
        
        $endTime = microtime(true);
        $loadTime = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);
        
        // Dashboard should load in less than 2000ms (2 seconds)
        $this->assertLessThan(2000, $loadTime,
            "Dashboard loaded in {$loadTime}ms, expected < 2000ms");
    }

    public function test_concurrent_product_views_performance(): void
    {
        $sanPham = SanPham::first();
        $times = [];

        // Simulate 10 concurrent requests
        for ($i = 0; $i < 10; $i++) {
            $startTime = microtime(true);
            
            $response = $this->get(route('products.show', $sanPham->ma_sp));
            
            $endTime = microtime(true);
            $times[] = ($endTime - $startTime) * 1000;

            $response->assertStatus(200);
        }

        $averageTime = array_sum($times) / count($times);

        // Average response time should be reasonable
        $this->assertLessThan(500, $averageTime,
            "Average response time: {$averageTime}ms, expected < 500ms");
    }

    public function test_multiple_cart_operations_performance(): void
    {
        $sanPhams = SanPham::take(5)->get();
        $times = [];

        foreach ($sanPhams as $sanPham) {
            $startTime = microtime(true);
            
            $response = $this->post(route('cart.add'), [
                'ma_sp' => $sanPham->ma_sp,
                'quantity' => 1
            ]);
            
            $endTime = microtime(true);
            $times[] = ($endTime - $startTime) * 1000;

            $response->assertRedirect();
        }

        $averageTime = array_sum($times) / count($times);

        // Cart operations should be fast
        $this->assertLessThan(200, $averageTime,
            "Average cart operation time: {$averageTime}ms, expected < 200ms");
    }

    public function test_search_query_performance_with_large_dataset(): void
    {
        $startTime = microtime(true);
        
        $response = $this->get(route('products.index', ['search' => 'Product']));
        
        $endTime = microtime(true);
        $searchTime = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);

        // Search should complete quickly even with many results
        $this->assertLessThan(500, $searchTime,
            "Search completed in {$searchTime}ms, expected < 500ms");
    }

    public function test_order_list_query_performance(): void
    {
        // Create many orders
        for ($i = 1; $i <= 50; $i++) {
            DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
                'tong_tien' => 50000,
                'trang_thai' => ['Pending', 'Delivering', 'Completed', 'Cancelled'][rand(0, 3)],
                'dia_chi_giao_hang' => '123 Test Street',
                'sdt_giao_hang' => '0901234567'
            ]);
        }

        $startTime = microtime(true);
        
        $response = $this->actingAs($this->admin)->get(route('admin.orders.index'));
        
        $endTime = microtime(true);
        $loadTime = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);

        // Order list should load quickly
        $this->assertLessThan(1000, $loadTime,
            "Order list loaded in {$loadTime}ms, expected < 1000ms");
    }

    public function test_database_query_optimization_for_complex_joins(): void
    {
        // Create orders with items
        for ($i = 1; $i <= 10; $i++) {
            $donHang = DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
                'tong_tien' => 100000,
                'trang_thai' => 'Completed',
                'dia_chi_giao_hang' => '123 Test Street',
                'sdt_giao_hang' => '0901234567'
            ]);

            $sanPhams = SanPham::take(3)->get();
            foreach ($sanPhams as $sanPham) {
                \App\Models\ChiTietDonHang::create(['ma_dh' => $donHang->ma_dh,
                    'ma_sp' => $sanPham->ma_sp,
                    'so_luong' => 2,
                    'don_gia' => $sanPham->gia,
                    'so_lo' => $sanPham->so_lo
                ]);
            }
        }

        DB::enableQueryLog();
        
        $startTime = microtime(true);
        
        // Complex query with joins
        $donHangs = DonHang::with(['nguoiDung', 'chiTietDonHangs.sanPham.danhMuc'])
            ->where('trang_thai', 'Completed')
            ->get();
        
        $endTime = microtime(true);
        $queryTime = ($endTime - $startTime) * 1000;

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Should use eager loading to minimize queries
        $this->assertLessThan(10, count($queries),
            "Used " . count($queries) . " queries, expected < 10 with eager loading");

        // Query should be fast
        $this->assertLessThan(500, $queryTime,
            "Complex query took {$queryTime}ms, expected < 500ms");
    }

    public function test_memory_usage_for_large_result_sets(): void
    {
        $memoryBefore = memory_get_usage();

        // Load products with pagination
        $sanPhams = SanPham::paginate(12);

        $memoryAfter = memory_get_usage();
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // Convert to MB

        // Memory usage should be reasonable (< 10MB for paginated results)
        $this->assertLessThan(10, $memoryUsed,
            "Memory usage: {$memoryUsed}MB, expected < 10MB");
    }

    public function test_response_time_consistency_under_load(): void
    {
        $times = [];

        // Simulate 20 requests
        for ($i = 0; $i < 20; $i++) {
            $startTime = microtime(true);
            
            $response = $this->get(route('home'));
            
            $endTime = microtime(true);
            $times[] = ($endTime - $startTime) * 1000;

            $response->assertStatus(200);
        }

        $averageTime = array_sum($times) / count($times);
        $maxTime = max($times);
        $minTime = min($times);

        // Response times should be consistent
        $variance = $maxTime - $minTime;
        
        $this->assertLessThan(500, $variance,
            "Response time variance: {$variance}ms, expected < 500ms");
        
        $this->assertLessThan(1000, $averageTime,
            "Average response time: {$averageTime}ms, expected < 1000ms");
    }
}
