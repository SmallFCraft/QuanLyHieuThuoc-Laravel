<?php

namespace Tests\Feature;

use App\Models\SanPham;
use App\Models\DanhMuc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GioHangTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a category
        $this->danhMuc = DanhMuc::create(['ten' => 'Test Category',
            'mo_ta' => 'Test Description'
        ]);
    }

    public function test_them_duoc_san_pham_vao_gio_hang(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Product',
            'gia' => 10000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-001'
        ]);

        $response = $this->post(route('gio-hang.them'), [
            'MaSP' => $sanPham->ma_sp,
            'quantity' => 2
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $cart = session('cart');
        $this->assertArrayHasKey($sanPham->ma_sp, $cart);
        $this->assertEquals(2, $cart[$sanPham->ma_sp]);
    }

    public function test_them_duoc_san_pham_vao_gio_hang_qua_json(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Ajax Product',
            'gia' => 15000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-AJAX-001'
        ]);

        $response = $this->postJson(route('gio-hang.them'), [
            'MaSP' => $sanPham->ma_sp,
            'quantity' => 3,
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'cart_count' => 1,
        ]);

        $cart = session('cart');
        $this->assertEquals(3, $cart[$sanPham->ma_sp]);
    }

    public function test_them_qua_json_tu_choi_san_pham_het_han(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Expired Ajax Product',
            'gia' => 10000,
            'ngay_het_han' => now()->subDays(1),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-AJAX-002'
        ]);

        $response = $this->postJson(route('gio-hang.them'), [
            'MaSP' => $sanPham->ma_sp,
            'quantity' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_khong_them_duoc_san_pham_het_han_vao_gio_hang(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Expired Product',
            'gia' => 10000,
            'ngay_het_han' => now()->subDays(1),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-002'
        ]);

        $response = $this->post(route('gio-hang.them'), [
            'MaSP' => $sanPham->ma_sp,
            'quantity' => 1
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_khong_them_duoc_qua_so_luong_ton_kho(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Limited Stock Product',
            'gia' => 10000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 5,
            'muc_canh_bao' => 2,
            'so_lo' => 'BATCH-003'
        ]);

        $response = $this->post(route('gio-hang.them'), [
            'MaSP' => $sanPham->ma_sp,
            'quantity' => 10
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_cap_nhat_duoc_so_luong_gio_hang(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Product',
            'gia' => 10000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-004'
        ]);

        // Add to cart first
        session(['cart' => [$sanPham->ma_sp => 2]]);

        $response = $this->put(route('gio-hang.cap-nhat', $sanPham->ma_sp), [
            'quantity' => 5
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        
        $cart = session('cart');
        $this->assertEquals(5, $cart[$sanPham->ma_sp]);
    }

    public function test_gio_hang_dung_gia_vnd_nguyen_khi_render_tong_tien(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Decimal Price Product',
            'gia' => 343487.26,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-DECIMAL-001'
        ]);

        session(['cart' => [$sanPham->ma_sp => 1]]);

        $response = $this->get(route('gio-hang.index'));

        $response->assertOk();
        $response->assertSee('data-price="343487"', false);
        $response->assertSee('343.487', false);
        $response->assertDontSee('343.487,26', false);
    }

    public function test_xoa_duoc_san_pham_khoi_gio_hang(): void
    {
        $sanPham = SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Test Product',
            'gia' => 10000,
            'ngay_het_han' => now()->addMonths(6),
            'so_luong_ton' => 10,
            'muc_canh_bao' => 5,
            'so_lo' => 'BATCH-005'
        ]);

        // Add to cart first
        session(['cart' => [$sanPham->ma_sp => 2]]);

        $response = $this->delete(route('gio-hang.xoa', $sanPham->ma_sp));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        
        $cart = session('cart');
        $this->assertArrayNotHasKey($sanPham->ma_sp, $cart);
    }
}
