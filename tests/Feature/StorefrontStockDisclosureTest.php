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
class StorefrontStockDisclosureTest extends TestCase
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

    public function test_the_detail_page_states_availability_without_a_count(): void
    {
        $sanPham = $this->product();

        $response = $this->get(route('products.show', $sanPham->ma_sp));

        $response->assertOk()
            ->assertSee('Còn hàng')
            ->assertDontSee('Còn 87 sản phẩm')
            ->assertDontSee('87 sản phẩm');
    }

    public function test_the_detail_page_shows_the_published_shelf_life_not_a_batch_date(): void
    {
        $sanPham = $this->product();

        $response = $this->get(route('products.show', $sanPham->ma_sp));

        $response->assertOk()
            ->assertSee('36 tháng')
            ->assertDontSee($sanPham->ngay_het_han->format('d/m/Y'));
    }

    public function test_a_product_without_a_published_shelf_life_shows_no_shelf_life_row(): void
    {
        $sanPham = $this->product(['han_dung_thang' => null]);

        $this->get(route('products.show', $sanPham->ma_sp))
            ->assertOk()
            ->assertDontSee('Hạn sử dụng');
    }

    public function test_the_internal_batch_number_is_not_published(): void
    {
        $sanPham = $this->product();

        $this->get(route('products.show', $sanPham->ma_sp))
            ->assertOk()
            ->assertDontSee('LC-00053294');
    }

    public function test_an_out_of_stock_product_says_so_and_hides_the_buy_form(): void
    {
        $sanPham = $this->product(['so_luong_ton' => 0, 'so_lo' => 'LC-EMPTY']);

        $this->get(route('products.show', $sanPham->ma_sp))
            ->assertOk()
            ->assertSee('Hết hàng')
            ->assertDontSee('id="buy-panel"', false);
    }

    public function test_product_cards_state_availability_without_a_count(): void
    {
        $this->product();

        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Còn hàng')
            ->assertDontSee('Còn 87 sản phẩm');
    }

    public function test_the_quantity_input_is_still_bounded_by_real_stock(): void
    {
        // Hiding the number must not hand the cart an unfillable quantity.
        $sanPham = $this->product(['so_luong_ton' => 3]);

        $this->get(route('products.show', $sanPham->ma_sp))
            ->assertOk()
            ->assertSee('max="3"', false);
    }

    public function test_admin_screens_still_show_the_exact_figures(): void
    {
        $sanPham = $this->product();
        $admin = NguoiDung::factory()->create(['vai_tro' => 'Administrator', 'trang_thai' => 'active']);

        // Staff run the inventory; only the customer-facing pages are reticent.
        $this->actingAs($admin)
            ->get(route('admin.inventory.index'))
            ->assertOk()
            ->assertSee('LC-00053294')
            ->assertSee((string) $sanPham->so_luong_ton);
    }
}
