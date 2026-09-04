<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
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

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'MaDM' => Category::create(['name' => 'Thuốc giảm đau'])->MaDM,
            'name' => 'Thuốc Silymax-F Hưng Việt',
            'price' => 132000,
            'shelf_life_months' => 36,
            'expiration_date' => now()->addMonths(36),
            'stock_quantity' => 87,
            'reorder_point' => 20,
            'batch_number' => 'LC-00053294',
        ], $overrides));
    }

    public function test_the_detail_page_states_availability_without_a_count(): void
    {
        $product = $this->product();

        $response = $this->get(route('products.show', $product->MaSP));

        $response->assertOk()
            ->assertSee('Còn hàng')
            ->assertDontSee('Còn 87 sản phẩm')
            ->assertDontSee('87 sản phẩm');
    }

    public function test_the_detail_page_shows_the_published_shelf_life_not_a_batch_date(): void
    {
        $product = $this->product();

        $response = $this->get(route('products.show', $product->MaSP));

        $response->assertOk()
            ->assertSee('36 tháng')
            ->assertDontSee($product->expiration_date->format('d/m/Y'));
    }

    public function test_a_product_without_a_published_shelf_life_shows_no_shelf_life_row(): void
    {
        $product = $this->product(['shelf_life_months' => null]);

        $this->get(route('products.show', $product->MaSP))
            ->assertOk()
            ->assertDontSee('Hạn sử dụng');
    }

    public function test_the_internal_batch_number_is_not_published(): void
    {
        $product = $this->product();

        $this->get(route('products.show', $product->MaSP))
            ->assertOk()
            ->assertDontSee('LC-00053294');
    }

    public function test_an_out_of_stock_product_says_so_and_hides_the_buy_form(): void
    {
        $product = $this->product(['stock_quantity' => 0, 'batch_number' => 'LC-EMPTY']);

        $this->get(route('products.show', $product->MaSP))
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
        $product = $this->product(['stock_quantity' => 3]);

        $this->get(route('products.show', $product->MaSP))
            ->assertOk()
            ->assertSee('max="3"', false);
    }

    public function test_admin_screens_still_show_the_exact_figures(): void
    {
        $product = $this->product();
        $admin = User::factory()->create(['role' => 'Administrator', 'status' => 'active']);

        // Staff run the inventory; only the customer-facing pages are reticent.
        $this->actingAs($admin)
            ->get(route('admin.inventory.index'))
            ->assertOk()
            ->assertSee('LC-00053294')
            ->assertSee((string) $product->stock_quantity);
    }
}
