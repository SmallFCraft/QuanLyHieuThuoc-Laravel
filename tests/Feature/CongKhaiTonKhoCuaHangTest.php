<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The storefront states availability and the manufacturer's shelf life, the way
 * Vietnamese pharmacy chains do. Exact stock counts and batch expiry dates are
 * internal working figures: the batch a customer receives is decided at pick time,
 * so printing one product's current batch date next to the buy button states
 * something the shop cannot promise.
 */
class CongKhaiTonKhoCuaHangTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): SanPham
    {
        return SanPham::create(array_merge([
            'ma_dm' => DanhMuc::create(['ten' => 'Thuốc giảm đau'])->ma_dm,
            'ten' => 'Thuốc Silymax-F Hưng Việt',
            'gia' => 132000,
            'han_dung_thang' => 36,
            'ngay_het_han' => now()->addMonths(36),
            'so_luong_ton' => 87,
            'muc_canh_bao' => 20,
            'so_lo' => 'LC-00053294',
        ], $overrides));
    }

    public function test_trang_chi_tiet_neu_tinh_trang_con_hang_khong_neu_so_luong(): void
    {
        $sanPham = $this->product();

        $response = $this->get(route('san-pham.show', $sanPham->ma_sp));

        $response->assertOk()
            ->assertSee('Còn hàng')
            ->assertDontSee('Còn 87 sản phẩm')
            ->assertDontSee('87 sản phẩm');
    }

    public function test_trang_chi_tiet_hien_han_su_dung_cong_bo_khong_hien_ngay_lo(): void
    {
        $sanPham = $this->product();

        $response = $this->get(route('san-pham.show', $sanPham->ma_sp));

        $response->assertOk()
            ->assertSee('36 tháng')
            ->assertDontSee($sanPham->ngay_het_han->format('d/m/Y'));
    }

    public function test_san_pham_khong_cong_bo_han_su_dung_khong_hien_dong_han_su_dung(): void
    {
        $sanPham = $this->product(['han_dung_thang' => null]);

        $this->get(route('san-pham.show', $sanPham->ma_sp))
            ->assertOk()
            ->assertDontSee('Hạn sử dụng');
    }

    public function test_so_lo_noi_bo_khong_duoc_cong_khai(): void
    {
        $sanPham = $this->product();

        $this->get(route('san-pham.show', $sanPham->ma_sp))
            ->assertOk()
            ->assertDontSee('LC-00053294');
    }

    public function test_san_pham_het_hang_bao_het_hang_va_an_form_mua(): void
    {
        $sanPham = $this->product(['so_luong_ton' => 0, 'so_lo' => 'LC-EMPTY']);

        $this->get(route('san-pham.show', $sanPham->ma_sp))
            ->assertOk()
            ->assertSee('Hết hàng')
            ->assertDontSee('id="buy-panel"', false);
    }

    public function test_the_san_pham_neu_tinh_trang_con_hang_khong_neu_so_luong(): void
    {
        $this->product();

        $this->get(route('san-pham.index'))
            ->assertOk()
            ->assertSee('Còn hàng')
            ->assertDontSee('Còn 87 sản phẩm');
    }

    public function test_o_so_luong_van_bi_gioi_han_boi_ton_kho_that(): void
    {
        // Hiding the number must not hand the cart an unfillable quantity.
        $sanPham = $this->product(['so_luong_ton' => 3]);

        $this->get(route('san-pham.show', $sanPham->ma_sp))
            ->assertOk()
            ->assertSee('max="3"', false);
    }

    public function test_man_hinh_quan_tri_van_hien_so_lieu_chinh_xac(): void
    {
        $sanPham = $this->product();
        $admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator', 'trang_thai' => 'active']);

        // Staff run the inventory; only the customer-facing pages are reticent.
        $this->actingAs($admin)
            ->get(route('admin.ton-kho.index'))
            ->assertOk()
            ->assertSee('LC-00053294')
            ->assertSee((string) $sanPham->so_luong_ton);
    }
}
