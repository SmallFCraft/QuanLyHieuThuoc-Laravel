<?php

namespace Tests\Feature;

use App\Models\NguoiDung;
use App\Models\SanPham;
use App\Models\DanhMuc;
use App\Models\DonHang;
use App\Models\ChiTietDonHang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;
    protected $nguoiDung;
    protected $danhMuc;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = NguoiDung::factory()->create([
            'role' => 'Administrator',
            'status' => 'active'
        ]);
        
        $this->nguoiDung = NguoiDung::factory()->create([
            'role' => 'User',
            'status' => 'active'
        ]);
        
        $this->danhMuc = DanhMuc::create([
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
        $response = $this->actingAs($this->nguoiDung)->get(route('admin.orders.index'));

        $response->assertStatus(403);
    }

    public function test_admin_can_update_order_status_from_pending_to_delivering(): void
    {
        $donHang = DonHang::create([
            'MaKH' => $this->nguoiDung->MaKH,
            'total_amount' => 20000,
            'status' => 'Pending',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.orders.updateStatus', $donHang->MaDH),
            ['status' => 'Delivering']
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $donHang->refresh();
        $this->assertEquals('Delivering', $donHang->status);
    }

    public function test_admin_can_update_order_status_from_delivering_to_completed(): void
    {
        $donHang = DonHang::create([
            'MaKH' => $this->nguoiDung->MaKH,
            'total_amount' => 20000,
            'status' => 'Delivering',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.orders.updateStatus', $donHang->MaDH),
            ['status' => 'Completed']
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $donHang->refresh();
        $this->assertEquals('Completed', $donHang->status);
    }

    public function test_cannot_update_completed_order_status(): void
    {
        $donHang = DonHang::create([
            'MaKH' => $this->nguoiDung->MaKH,
            'total_amount' => 20000,
            'status' => 'Completed',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        $response = $this->actingAs($this->admin)->patch(
            route('admin.orders.updateStatus', $donHang->MaDH),
            ['status' => 'Pending']
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $donHang->refresh();
        $this->assertEquals('Completed', $donHang->status);
    }

    public function test_admin_can_cancel_pending_order_and_restore_inventory(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 5,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-001'
        ]);

        $donHang = DonHang::create([
            'MaKH' => $this->nguoiDung->MaKH,
            'total_amount' => 20000,
            'status' => 'Pending',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        ChiTietDonHang::create([
            'MaDH' => $donHang->MaDH,
            'MaSP' => $sanPham->MaSP,
            'quantity' => 2,
            'unit_price' => 10000,
            'batch_number' => 'BATCH-001'
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('admin.orders.cancel', $donHang->MaDH)
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify order status changed to Cancelled
        $donHang->refresh();
        $this->assertEquals('Cancelled', $donHang->status);

        // Verify inventory was restored
        $sanPham->refresh();
        $this->assertEquals(7, $sanPham->stock_quantity);
    }

    public function test_cannot_cancel_completed_order(): void
    {
        $sanPham = SanPham::create([
            'MaDM' => $this->danhMuc->MaDM,
            'name' => 'Test Product',
            'price' => 10000,
            'expiration_date' => now()->addMonths(6),
            'stock_quantity' => 5,
            'reorder_point' => 5,
            'batch_number' => 'BATCH-002'
        ]);

        $donHang = DonHang::create([
            'MaKH' => $this->nguoiDung->MaKH,
            'total_amount' => 20000,
            'status' => 'Completed',
            'shipping_address' => '123 Test Street',
            'shipping_phone' => '0901234567'
        ]);

        ChiTietDonHang::create([
            'MaDH' => $donHang->MaDH,
            'MaSP' => $sanPham->MaSP,
            'quantity' => 2,
            'unit_price' => 10000,
            'batch_number' => 'BATCH-002'
        ]);

        $response = $this->actingAs($this->admin)->post(
            route('admin.orders.cancel', $donHang->MaDH)
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Verify order status did not change
        $donHang->refresh();
        $this->assertEquals('Completed', $donHang->status);

        // Verify inventory was not changed
        $sanPham->refresh();
        $this->assertEquals(5, $sanPham->stock_quantity);
    }
}
