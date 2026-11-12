<?php

namespace Tests\Feature;

use App\Models\NguoiDung;
use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\DonHang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected $nguoiDung;
    protected $danhMuc;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->nguoiDung = NguoiDung::factory()->create([
            'role' => 'User',
            'status' => 'active'
        ]);
        
        $this->danhMuc = DanhMuc::create([
            'name' => 'Test Category',
            'description' => 'Test Description'
        ]);
    }

    public function test_authenticated_user_can_access_checkout(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-001'
        ]);

        session(['cart' => [$sanPham->MaSP => 2]]);

        $response = $this->actingAs($this->nguoiDung)->get(route('checkout.index'));

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
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-002'
        ]);

        session(['cart' => [$sanPham->MaSP => 2]]);

        $response = $this->actingAs($this->nguoiDung)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify order was created
        $this->assertDatabaseHas('orders', [
            'MaKH' => $this->nguoiDung->MaKH,
            'status' => 'Pending',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        // Verify order items were created
        $donHang = DonHang::where('MaKH', $this->nguoiDung->MaKH)->first();
        $this->assertDatabaseHas('order_items', [
            'MaDH' => $donHang->MaDH,
            'MaSP' => $sanPham->MaSP,
            'quantity' => 2
        ]);

        // Verify inventory was updated
        $sanPham->refresh();
        $this->assertEquals(8, $sanPham->stock_quantity);

        // Verify cart was cleared
        $this->assertNull(session('cart'));
    }

    public function test_checkout_fails_with_insufficient_stock(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Limited Stock Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 1,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-003'
        ]);

        session(['cart' => [$sanPham->MaSP => 5]]);

        $response = $this->actingAs($this->nguoiDung)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Verify no order was created
        $this->assertDatabaseMissing('orders', [
            'MaKH' => $this->nguoiDung->MaKH
        ]);

        // Verify inventory was not changed
        $sanPham->refresh();
        $this->assertEquals(1, $sanPham->stock_quantity);
    }

    public function test_checkout_fails_with_expired_product(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Expired Product',
            'price' => 10000,
            'expiration_date' => now()->subDays(1),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-004'
        ]);

        session(['cart' => [$sanPham->MaSP => 2]]);

        $response = $this->actingAs($this->nguoiDung)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Verify no order was created
        $this->assertDatabaseMissing('orders', [
            'MaKH' => $this->nguoiDung->MaKH
        ]);
    }

    public function test_checkout_requires_valid_phone_number(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 10,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-005'
        ]);

        session(['cart' => [$sanPham->MaSP => 2]]);

        $response = $this->actingAs($this->nguoiDung)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => 'invalid-phone'
        ]);

        $response->assertSessionHasErrors('shipping_phone');
    }
}
