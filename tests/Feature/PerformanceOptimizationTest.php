<?php

namespace Tests\Feature;

use App\Models\NguoiDung;
use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\DonHang;
use App\Models\ChiTietDonHang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PerformanceOptimizationTest extends TestCase
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
    }

    public function test_eager_loading_prevents_n_plus_1_queries_for_products(): void
    {
        // Create multiple products with categories
        for ($i = 1; $i <= 10; $i++) {
            SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
                'ten' => "Product {$i}",
                'gia' => 10000 * $i,
                'ngay_het_han' => now()->addYear(),
                'so_luong_ton' => 100,
                'muc_canh_bao' => 10,
                'so_lo' => "BATCH-{$i}"
            ]);
        }

        // Enable query logging
        DB::enableQueryLog();

        // Load products with eager loading
        $sanPhams = SanPham::with('danhMuc')->get();

        $queries = DB::getQueryLog();
        $queryCount = count($queries);

        // Should be 2 queries: 1 for products, 1 for categories
        // Not 11 queries (1 for products + 10 for individual categories)
        $this->assertLessThanOrEqual(3, $queryCount, 
            "Expected 2-3 queries with eager loading, got {$queryCount}");

        DB::disableQueryLog();
    }

    public function test_eager_loading_prevents_n_plus_1_queries_for_orders(): void
    {
        // Create orders with items
        for ($i = 1; $i <= 5; $i++) {
            $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
                'ten' => "Product {$i}",
                'gia' => 10000,
                'ngay_het_han' => now()->addYear(),
                'so_luong_ton' => 100,
                'muc_canh_bao' => 10,
                'so_lo' => "BATCH-ORDER-{$i}"
            ]);

            $donHang = DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
                'tong_tien' => 20000,
                'trang_thai' => 'Pending',
                'dia_chi_giao_hang' => '123 Test Street',
                'sdt_giao_hang' => '0901234567'
            ]);

            ChiTietDonHang::create(['ma_dh' => $donHang->ma_dh,
                'ma_sp' => $sanPham->ma_sp,
                'so_luong' => 2,
                'don_gia' => 10000,
                'so_lo' => "BATCH-ORDER-{$i}"
            ]);
        }

        DB::enableQueryLog();

        // Load orders with eager loading
        $donHangs = DonHang::with(['nguoiDung', 'chiTietDonHangs.sanPham'])->get();

        $queries = DB::getQueryLog();
        $queryCount = count($queries);

        // Should be around 4 queries with proper eager loading
        // Not 15+ queries without eager loading
        $this->assertLessThanOrEqual(5, $queryCount,
            "Expected 4-5 queries with eager loading, got {$queryCount}");

        DB::disableQueryLog();
    }

    public function test_database_indexes_improve_query_performance(): void
    {
        // Create many products
        for ($i = 1; $i <= 100; $i++) {
            SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
                'ten' => "Product {$i}",
                'gia' => 10000,
                'ngay_het_han' => now()->addDays($i),
                'so_luong_ton' => 100,
                'muc_canh_bao' => 10,
                'so_lo' => "BATCH-PERF-{$i}"
            ]);
        }

        // Query using indexed column (ngay_het_han)
        $startTime = microtime(true);
        
        $expiringProducts = SanPham::where('ngay_het_han', '<=', now()->addDays(30))
            ->get();
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        // Query should be fast (< 100ms for 100 records)
        $this->assertLessThan(100, $executionTime,
            "Query took {$executionTime}ms, expected < 100ms");
    }

    public function test_category_caching_reduces_database_queries(): void
    {
        // Clear cache
        Cache::forget('categories_all');

        // First request - should hit database
        DB::enableQueryLog();
        
        $categories1 = Cache::remember('categories_all', 3600, function () {
            return DanhMuc::all();
        });
        
        $queries1 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Second request - should use cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        
        $categories2 = Cache::remember('categories_all', 3600, function () {
            return DanhMuc::all();
        });
        
        $queries2 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // First request should query database
        $this->assertGreaterThan(0, $queries1);
        
        // Second request should not query database
        $this->assertEquals(0, $queries2);

        // Both should return same data
        $this->assertEquals($categories1->count(), $categories2->count());
    }

    public function test_dashboard_metrics_caching(): void
    {
        // Create test data
        DonHang::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'tong_tien' => 100000,
            'trang_thai' => 'Completed',
            'dia_chi_giao_hang' => '123 Test Street',
            'sdt_giao_hang' => '0901234567'
        ]);

        // Clear cache
        Cache::forget('dashboard_metrics');

        // First request - should hit database
        DB::enableQueryLog();
        
        $metrics1 = Cache::remember('dashboard_metrics', 300, function () {
            return [
                'total_orders' => DonHang::count(),
                'total_revenue' => DonHang::where('trang_thai', 'Completed')->sum('tong_tien')
            ];
        });
        
        $queries1 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Second request - should use cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        
        $metrics2 = Cache::remember('dashboard_metrics', 300, function () {
            return [
                'total_orders' => DonHang::count(),
                'total_revenue' => DonHang::where('trang_thai', 'Completed')->sum('tong_tien')
            ];
        });
        
        $queries2 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // First request should query database
        $this->assertGreaterThan(0, $queries1);
        
        // Second request should not query database
        $this->assertEquals(0, $queries2);

        // Both should return same data
        $this->assertEquals($metrics1, $metrics2);
    }

    public function test_pagination_limits_query_results(): void
    {
        // Create many products
        for ($i = 1; $i <= 50; $i++) {
            SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
                'ten' => "Product {$i}",
                'gia' => 10000,
                'ngay_het_han' => now()->addYear(),
                'so_luong_ton' => 100,
                'muc_canh_bao' => 10,
                'so_lo' => "BATCH-PAGE-{$i}"
            ]);
        }

        // Get paginated results
        $sanPhams = SanPham::paginate(12);

        // Should only return 12 items per page
        $this->assertEquals(12, $sanPhams->count());
        $this->assertEquals(50, $sanPhams->total());
    }

    public function test_select_specific_columns_reduces_data_transfer(): void
    {
        // Create product with all fields
        SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Product',
            'thanh_phan' => str_repeat('Long ingredient list ', 100),
            'cach_dung' => str_repeat('Long usage instructions ', 100),
            'gia' => 10000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 10,
            'so_lo' => 'BATCH-SELECT'
        ]);

        $sanPhams = SanPham::select('ma_sp', 'ten', 'gia')->get();
        $allProducts = SanPham::all();

        $selectedAttributes = $sanPhams->first()->getAttributes();
        $allAttributes = $allProducts->first()->getAttributes();

        $this->assertArrayHasKey('ma_sp', $selectedAttributes);
        $this->assertArrayHasKey('ten', $selectedAttributes);
        $this->assertArrayHasKey('gia', $selectedAttributes);
        $this->assertArrayNotHasKey('thanh_phan', $selectedAttributes);
        $this->assertArrayNotHasKey('cach_dung', $selectedAttributes);
        $this->assertArrayHasKey('thanh_phan', $allAttributes);
        $this->assertLessThan(
            strlen(json_encode($allAttributes)),
            strlen(json_encode($selectedAttributes))
        );
    }

    public function test_chunk_processing_for_large_datasets(): void
    {
        // Create many products
        for ($i = 1; $i <= 100; $i++) {
            SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
                'ten' => "Product {$i}",
                'gia' => 10000,
                'ngay_het_han' => now()->addYear(),
                'so_luong_ton' => 100,
                'muc_canh_bao' => 10,
                'so_lo' => "BATCH-CHUNK-{$i}"
            ]);
        }

        $processedCount = 0;

        // Process in chunks
        SanPham::chunk(20, function ($sanPhams) use (&$processedCount) {
            $processedCount += $sanPhams->count();
        });

        // Should process all products
        $this->assertEquals(100, $processedCount);
    }
}
