<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PerformanceOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $user;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = User::factory()->create([
            'role' => 'Administrator',
            'status' => 'active'
        ]);
        
        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active'
        ]);
        
        $this->category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test Description'
        ]);
    }

    public function test_eager_loading_prevents_n_plus_1_queries_for_products(): void
    {
        // Create multiple products with categories
        for ($i = 1; $i <= 10; $i++) {
            Product::create([
                'MaDM' => $this->category->MaDM,
                'name' => "Product {$i}",
                'price' => 10000 * $i,
                'expiration_date' => now()->addYear(),
                'stock_quantity' => 100,
                'reorder_point' => 10,
                'batch_number' => "BATCH-{$i}"
            ]);
        }

        // Enable query logging
        DB::enableQueryLog();

        // Load products with eager loading
        $products = Product::with('category')->get();

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
            $product = Product::create([
                'MaDM' => $this->category->MaDM,
                'name' => "Product {$i}",
                'price' => 10000,
                'expiration_date' => now()->addYear(),
                'stock_quantity' => 100,
                'reorder_point' => 10,
                'batch_number' => "BATCH-ORDER-{$i}"
            ]);

            $order = Order::create([
                'MaKH' => $this->user->MaKH,
                'total_amount' => 20000,
                'status' => 'Pending',
                'shipping_address' => '123 Test Street',
                'shipping_phone' => '0901234567'
            ]);

            OrderItem::create([
                'MaDH' => $order->MaDH,
                'MaSP' => $product->MaSP,
                'quantity' => 2,
                'unit_price' => 10000,
                'batch_number' => "BATCH-ORDER-{$i}"
            ]);
        }

        DB::enableQueryLog();

        // Load orders with eager loading
        $orders = Order::with(['user', 'items.product'])->get();

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
            Product::create([
                'MaDM' => $this->category->MaDM,
                'name' => "Product {$i}",
                'price' => 10000,
                'expiration_date' => now()->addDays($i),
                'stock_quantity' => 100,
                'reorder_point' => 10,
                'batch_number' => "BATCH-PERF-{$i}"
            ]);
        }

        // Query using indexed column (expiration_date)
        $startTime = microtime(true);
        
        $expiringProducts = Product::where('expiration_date', '<=', now()->addDays(30))
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
            return Category::all();
        });
        
        $queries1 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Second request - should use cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        
        $categories2 = Cache::remember('categories_all', 3600, function () {
            return Category::all();
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
        Order::create([
            'MaKH' => $this->user->MaKH,
            'total_amount' => 100000,
            'status' => 'Completed',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        // Clear cache
        Cache::forget('dashboard_metrics');

        // First request - should hit database
        DB::enableQueryLog();
        
        $metrics1 = Cache::remember('dashboard_metrics', 300, function () {
            return [
                'total_orders' => Order::count(),
                'total_revenue' => Order::where('status', 'Completed')->sum('total_amount')
            ];
        });
        
        $queries1 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Second request - should use cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        
        $metrics2 = Cache::remember('dashboard_metrics', 300, function () {
            return [
                'total_orders' => Order::count(),
                'total_revenue' => Order::where('status', 'Completed')->sum('total_amount')
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
            Product::create([
                'MaDM' => $this->category->MaDM,
                'name' => "Product {$i}",
                'price' => 10000,
                'expiration_date' => now()->addYear(),
                'stock_quantity' => 100,
                'reorder_point' => 10,
                'batch_number' => "BATCH-PAGE-{$i}"
            ]);
        }

        // Get paginated results
        $products = Product::paginate(12);

        // Should only return 12 items per page
        $this->assertEquals(12, $products->count());
        $this->assertEquals(50, $products->total());
    }

    public function test_select_specific_columns_reduces_data_transfer(): void
    {
        // Create product with all fields
        Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'ingredients' => str_repeat('Long ingredient list ', 100),
            'usage' => str_repeat('Long usage instructions ', 100),
            'price' => 10000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'BATCH-SELECT'
        ]);

        $products = Product::select('MaSP', 'name', 'price')->get();
        $allProducts = Product::all();

        $selectedAttributes = $products->first()->getAttributes();
        $allAttributes = $allProducts->first()->getAttributes();

        $this->assertArrayHasKey('MaSP', $selectedAttributes);
        $this->assertArrayHasKey('name', $selectedAttributes);
        $this->assertArrayHasKey('price', $selectedAttributes);
        $this->assertArrayNotHasKey('ingredients', $selectedAttributes);
        $this->assertArrayNotHasKey('usage', $selectedAttributes);
        $this->assertArrayHasKey('ingredients', $allAttributes);
        $this->assertLessThan(
            strlen(json_encode($allAttributes)),
            strlen(json_encode($selectedAttributes))
        );
    }

    public function test_chunk_processing_for_large_datasets(): void
    {
        // Create many products
        for ($i = 1; $i <= 100; $i++) {
            Product::create([
                'MaDM' => $this->category->MaDM,
                'name' => "Product {$i}",
                'price' => 10000,
                'expiration_date' => now()->addYear(),
                'stock_quantity' => 100,
                'reorder_point' => 10,
                'batch_number' => "BATCH-CHUNK-{$i}"
            ]);
        }

        $processedCount = 0;

        // Process in chunks
        Product::chunk(20, function ($products) use (&$processedCount) {
            $processedCount += $products->count();
        });

        // Should process all products
        $this->assertEquals(100, $processedCount);
    }
}
