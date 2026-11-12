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
        
        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active'
        ]);
        
        $this->danhMuc = DanhMuc::create(['ten' => 'Test Category',
            'mo_ta' => 'Test Description'
        ]);
    }

    public function test_authenticated_user_can_access_checkout(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Product',
            'gia' => 10000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-001'
        ]);

        session(['cart' => [$sanPham->ma_sp => 2]]);

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
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Product',
            'gia' => 10000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-002'
        ]);

        session(['cart' => [$sanPham->ma_sp => 2]]);

        $response = $this->actingAs($this->nguoiDung)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify order was created
        $this->assertDatabaseHas('don_hangs', [
            'ma_nd' => $this->nguoiDung->ma_nd,
            'trang_thai' => 'Pending',
            'dia_chi_giao_hang' => '123 Test Street, Test City',
            'sdt_giao_hang' => '0901234567'
        ]);

        // Verify order items were created
        $donHang = DonHang::where('ma_nd', $this->nguoiDung->ma_nd)->first();
        $this->assertDatabaseHas('chi_tiet_don_hangs', [
            'ma_dh' => $donHang->ma_dh,
            'ma_sp' => $sanPham->ma_sp,
            'so_luong' => 2
        ]);

        // Verify inventory was updated
        $sanPham->refresh();
        $this->assertEquals(8, $sanPham->so_luong_ton);

        // Verify cart was cleared
        $this->assertNull(session('cart'));
    }

    public function test_checkout_fails_with_insufficient_stock(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Limited Stock Product',
            'gia' => 10000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 1,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-003'
        ]);

        session(['cart' => [$sanPham->ma_sp => 5]]);

        $response = $this->actingAs($this->nguoiDung)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Verify no order was created
        $this->assertDatabaseMissing('don_hangs', [
            'ma_nd' => $this->nguoiDung->ma_nd
        ]);

        // Verify inventory was not changed
        $sanPham->refresh();
        $this->assertEquals(1, $sanPham->so_luong_ton);
    }

    public function test_checkout_fails_with_expired_product(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Expired Product',
            'gia' => 10000,
            'ngay_het_han' => now()->subDays(1),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-004'
        ]);

        session(['cart' => [$sanPham->ma_sp => 2]]);

        $response = $this->actingAs($this->nguoiDung)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => '0901234567'
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');

        // Verify no order was created
        $this->assertDatabaseMissing('don_hangs', [
            'ma_nd' => $this->nguoiDung->ma_nd
        ]);
    }

    public function test_checkout_requires_valid_phone_number(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Product',
            'gia' => 10000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-005'
        ]);

        session(['cart' => [$sanPham->ma_sp => 2]]);

        $response = $this->actingAs($this->nguoiDung)->post(route('checkout.process'), [
            'customer_name' => 'Test User',
            'shipping_address' => '123 Test Street, Test City',
            'shipping_phone' => 'invalid-phone'
        ]);

        $response->assertSessionHasErrors('shipping_phone');
    }
}
