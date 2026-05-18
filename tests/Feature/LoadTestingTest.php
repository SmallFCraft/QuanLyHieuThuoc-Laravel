<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoadTestingTest extends TestCase
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

        // Create test products
        for ($i = 1; $i <= 50; $i++) {
            Product::create([
                'MaDM' => $this->category->MaDM,
                'name' => "Product {$i}",
                'price' => 10000 * $i,
                'expiration_date' => now()->addYear(),
                'stock_quantity' => 100,
                'reorder_point' => 10,
                'batch_number' => "BATCH-LOAD-{$i}"
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
            Order::create([
                'MaKH' => $this->user->MaKH,
                'total_amount' => 50000,
                'status' => ['Pending', 'Delivering', 'Completed', 'Cancelled'][rand(0, 3)],
                'shipping_address' => '123 Test Street',
                'shipping_phone' => '0901234567'
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
        $product = Product::first();
        $times = [];

        // Simulate 10 concurrent requests
        for ($i = 0; $i < 10; $i++) {
            $startTime = microtime(true);
            
            $response = $this->get(route('products.show', $product->MaSP));
            
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
        $products = Product::take(5)->get();
        $times = [];

        foreach ($products as $product) {
            $startTime = microtime(true);
            
            $response = $this->post(route('cart.add'), [
                'MaSP' => $product->MaSP,
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
            Order::create([
                'MaKH' => $this->user->MaKH,
                'total_amount' => 50000,
                'status' => ['Pending', 'Delivering', 'Completed', 'Cancelled'][rand(0, 3)],
                'shipping_address' => '123 Test Street',
                'shipping_phone' => '0901234567'
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
            $order = Order::create([
                'MaKH' => $this->user->MaKH,
                'total_amount' => 100000,
                'status' => 'Completed',
                'shipping_address' => '123 Test Street',
                'shipping_phone' => '0901234567'
            ]);

            $products = Product::take(3)->get();
            foreach ($products as $product) {
                \App\Models\OrderItem::create([
                    'MaDH' => $order->MaDH,
                    'MaSP' => $product->MaSP,
                    'quantity' => 2,
                    'unit_price' => $product->price,
                    'batch_number' => $product->batch_number
                ]);
            }
        }

        DB::enableQueryLog();
        
        $startTime = microtime(true);
        
        // Complex query with joins
        $orders = Order::with(['user', 'items.product.category'])
            ->where('status', 'Completed')
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
        $products = Product::paginate(12);

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
