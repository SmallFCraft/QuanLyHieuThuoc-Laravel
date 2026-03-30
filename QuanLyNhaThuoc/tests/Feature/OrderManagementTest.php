<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderManagementTest extends TestCase
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

    public function test_admin_can_view_orders_list(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.orders.index'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.orders.index');
    }

    public function test_non_admin_cannot_access_orders_management(): void
    {
        $response = $this->actingAs($this->user)->get(route('admin.orders.index'));

        $response->assertStatus(403);
    }

    public function test_admin_can_update_order_status_from_pending_to_delivering(): void
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'total_amount' => 20000,
            'status' => 'Pending',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.orders.updateStatus', $order->id),
            ['status' => 'Delivering']
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('Delivering', $order->status);
    }

    public function test_admin_can_update_order_status_from_delivering_to_completed(): void
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'total_amount' => 20000,
            'status' => 'Delivering',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.orders.updateStatus', $order->id),
            ['status' => 'Completed']
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('Completed', $order->status);
    }

    public function test_cannot_update_completed_order_status(): void
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'total_amount' => 20000,
            'status' => 'Completed',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.orders.updateStatus', $order->id),
            ['status' => 'Pending']
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $order->refresh();
        $this->assertEquals('Completed', $order->status);
    }

    public function test_admin_can_cancel_pending_order_and_restore_inventory(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 5,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-001'
        ]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'total_amount' => 20000,
            'status' => 'Pending',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 10000,
            'batch_number' => 'BATCH-001'
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('admin.orders.cancel', $order->id)
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify order status changed to Cancelled
        $order->refresh();
        $this->assertEquals('Cancelled', $order->status);

        // Verify inventory was restored
        $product->refresh();
        $this->assertEquals(7, $product->stock_quantity);
    }

    public function test_cannot_cancel_completed_order(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 5,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-002'
        ]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'total_amount' => 20000,
            'status' => 'Completed',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 10000,
            'batch_number' => 'BATCH-002'
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('admin.orders.cancel', $order->id)
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Verify order status did not change
        $order->refresh();
        $this->assertEquals('Completed', $order->status);

        // Verify inventory was not changed
        $product->refresh();
        $this->assertEquals(5, $product->stock_quantity);
    }
}
