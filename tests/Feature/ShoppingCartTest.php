<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a category
        $this->category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test Description'
        ]);
    }

    public function test_can_add_product_to_cart(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-001'
        ]);

        $response = $this->post(route('cart.add'), [
            'MaSP' => $product->MaSP,
            'quantity' => 2
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cart = session('cart');
        $this->assertArrayHasKey($product->MaSP, $cart);
        $this->assertEquals(2, $cart[$product->MaSP]);
    }

    public function test_can_add_product_to_cart_via_json(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Ajax Product',
            'price' => 15000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-AJAX-001'
        ]);

        $response = $this->postJson(route('cart.add'), [
            'MaSP' => $product->MaSP,
            'quantity' => 3,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'cart_count' => 1,
        ]);

        $cart = session('cart');
        $this->assertEquals(3, $cart[$product->MaSP]);
    }

    public function test_json_add_rejects_expired_product(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Expired Ajax Product',
            'price' => 10000,
            'expiration_date' => now()->subDays(1),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-AJAX-002'
        ]);

        $response = $this->postJson(route('cart.add'), [
            'MaSP' => $product->MaSP,
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_cannot_add_expired_product_to_cart(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Expired Product',
            'price' => 10000,
            'expiration_date' => now()->subDays(1),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-002'
        ]);

        $response = $this->post(route('cart.add'), [
            'MaSP' => $product->MaSP,
            'quantity' => 1
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_cannot_add_more_than_stock_quantity(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Limited Stock Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 5,
            'reorder_point' => 2,
            'batch_number' => 'BATCH-003'
        ]);

        $response = $this->post(route('cart.add'), [
            'MaSP' => $product->MaSP,
            'quantity' => 10
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_can_update_cart_quantity(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-004'
        ]);

        // Add to cart first
        session(['cart' => [$product->MaSP => 2]]);

        $response = $this->put(route('cart.update', $product->MaSP), [
            'quantity' => 5
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        
        $cart = session('cart');
        $this->assertEquals(5, $cart[$product->MaSP]);
    }

    public function test_cart_uses_whole_vnd_price_when_rendering_totals(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Decimal Price Product',
            'price' => 343487.26,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-DECIMAL-001'
        ]);

        session(['cart' => [$product->MaSP => 1]]);

        $response = $this->get(route('cart.index'));

        $response->assertOk();
        $response->assertSee('data-price="343487"', false);
        $response->assertSee('343.487', false);
        $response->assertDontSee('343.487,26', false);
    }

    public function test_can_remove_product_from_cart(): void
    {
        $product = Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-005'
        ]);

        // Add to cart first
        session(['cart' => [$product->MaSP => 2]]);

        $response = $this->delete(route('cart.remove', $product->MaSP));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        
        $cart = session('cart');
        $this->assertArrayNotHasKey($product->MaSP, $cart);
    }
}
