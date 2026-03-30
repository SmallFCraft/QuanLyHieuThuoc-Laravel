<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active'
        ]);
        
        $this->category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test Description'
        ]);
    }

    public function test_authenticated_user_can_access_checkout(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-001'
        ]);

        session(['cart' => [$product->id => 2]]);

        $response = $this->actingAs($this->user)->get(route('checkout.index'));

        $response->assertStatus(200);
        $response->assertViewIs('checkout.index');
    }

    public function test_guest_cannot_access_checkout(): void
    {
        $response = $this->get(route('checkout.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_can_complete_checkout_with_valid_data(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-002'
        ]);

        session(['cart' => [$product->id => 2]]);

        $response = $this->actingAs($this->user)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify order was created
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'status' => 'Pending',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        // Verify order items were created
        $order = Order::where('user_id', $this->user->id)->first();
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2
        ]);

        // Verify inventory was updated
        $product->refresh();
        $this->assertEquals(8, $product->stock_quantity);

        // Verify cart was cleared
        $this->assertNull(session('cart'));
    }

    public function test_checkout_fails_with_insufficient_stock(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Limited Stock Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 1,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-003'
        ]);

        session(['cart' => [$product->id => 5]]);

        $response = $this->actingAs($this->user)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Verify no order was created
        $this->assertDatabaseMissing('orders', [
            'user_id' => $this->user->id
        ]);

        // Verify inventory was not changed
        $product->refresh();
        $this->assertEquals(1, $product->stock_quantity);
    }

    public function test_checkout_fails_with_expired_product(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Expired Product',
            'price' => 10000,
            'expiration_date' => now()->subDays(1),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-004'
        ]);

        session(['cart' => [$product->id => 2]]);

        $response = $this->actingAs($this->user)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Verify no order was created
        $this->assertDatabaseMissing('orders', [
            'user_id' => $this->user->id
        ]);
    }

    public function test_checkout_requires_valid_phone_number(): void
    {
        $product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-005'
        ]);

        session(['cart' => [$product->id => 2]]);

        $response = $this->actingAs($this->user)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => 'invalid-phone'
        ]);

        $response->assertSessionHasErrors('shipping_phone');
    }
}
