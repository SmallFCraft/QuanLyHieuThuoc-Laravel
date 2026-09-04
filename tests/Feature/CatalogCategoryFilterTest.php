<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The category filter on the catalog page.
 *
 * The crawled catalog carries 51 categories. Listed flat the panel measured
 * 3,898px, which on mobile sat entirely above the first product — so the list now
 * scrolls inside a frame, collapses on small screens, and leads with the
 * categories that actually hold stock.
 */
class CatalogCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock('Dụng cụ y tế', 6);
        $this->stock('Thuốc tiêu hoá & gan mật', 3);
        $this->stock('Dược mỹ phẩm', 1);
    }

    private function stock(string $categoryName, int $products): Category
    {
        $category = Category::create(['name' => $categoryName]);

        for ($i = 0; $i < $products; $i++) {
            Product::create([
                'MaDM' => $category->MaDM,
                'name' => "{$categoryName} sản phẩm {$i}",
                'price' => 50000,
                'expiration_date' => now()->addYears(2),
                'stock_quantity' => 10,
                'reorder_point' => 5,
                'batch_number' => 'LC-' . md5($categoryName . $i),
            ]);
        }

        return $category;
    }

    /** @return array<string> Category names in the order the filter renders them. */
    private function renderedOrder(string $url = '/products'): array
    {
        $html = $this->get($url)->assertOk()->getContent();

        // Anchored on data-folded, which only the category rows carry — the "all"
        // row has no folded name because it is never filtered out.
        preg_match_all(
            '#data-folded="[^"]*"[^>]*>\s*<span class="catalog-filter-label">([^<]+)<#s',
            $html,
            $matches
        );

        return array_map(
            fn (string $name) => html_entity_decode($name, ENT_QUOTES, 'UTF-8'),
            $matches[1]
        );
    }

    public function test_categories_lead_with_the_most_stock(): void
    {
        $this->assertSame(
            ['Dụng cụ y tế', 'Thuốc tiêu hoá & gan mật', 'Dược mỹ phẩm'],
            $this->renderedOrder()
        );
    }

    public function test_a_category_without_stock_is_left_out(): void
    {
        Category::create(['name' => 'Danh mục rỗng']);

        $this->assertNotContains('Danh mục rỗng', $this->renderedOrder());
    }

    public function test_the_all_row_counts_the_whole_catalog_even_while_filtered(): void
    {
        $category = Category::where('name', 'Dược mỹ phẩm')->first();

        // Reading $products->total() here showed the filtered count, so the "all"
        // row claimed 1 product while sitting above 10.
        //
        // Anchored on catalog-filter-count: a bare '>10</span>' also matches the header
        // dropdown's own total, so the regression above would still pass.
        $this->get("/products?category={$category->MaDM}")
            ->assertOk()
            ->assertSee('<span class="catalog-filter-count">10</span>', false);
    }

    public function test_each_row_carries_its_stock_depth_and_a_folded_name(): void
    {
        // Chỉ phần sidebar catalog: dropdown header render cùng --depth và data-folded,
        // nên quét cả trang thì xoá hẳn hai thuộc tính ở sidebar vẫn xanh.
        $html = $this->catalogFilterMarkup($this->get('/products')->assertOk()->getContent());

        // The fullest category is the full bar; the rest are proportional.
        $this->assertStringContainsString('--depth: 100%', $html);
        $this->assertStringContainsString('--depth: 50%', $html);

        // Folded here, at render time, so the client-side filter matches an
        // accent-free query without shipping a folding table.
        $this->assertStringContainsString('data-folded="duoc my pham"', $html);
        $this->assertStringContainsString('data-folded="dung cu y te"', $html);
    }

    /** Phần HTML của riêng bộ lọc sidebar, tính từ hàng "Tất cả". */
    private function catalogFilterMarkup(string $html): string
    {
        $start = strpos($html, 'catalog-filter-link is-all');

        $this->assertNotFalse($start, 'Bộ lọc catalog không có trên trang.');

        return substr($html, $start);
    }

    public function test_the_mobile_summary_names_the_active_category(): void
    {
        $category = Category::where('name', 'Dụng cụ y tế')->first();

        $this->get("/products?category={$category->MaDM}")
            ->assertOk()
            ->assertSee('catalog-filter__toggle-current">Dụng cụ y tế', false);
    }

    public function test_the_mobile_summary_falls_back_to_all(): void
    {
        $this->get('/products')
            ->assertOk()
            ->assertSee('catalog-filter__toggle-current">Tất cả', false);
    }

    public function test_the_active_category_is_marked_for_assistive_tech(): void
    {
        $category = Category::where('name', 'Dụng cụ y tế')->first();

        $this->get("/products?category={$category->MaDM}")
            ->assertOk()
            ->assertSee('aria-current="page"', false);

        $this->get('/products')->assertOk()->assertSee('aria-current="page"', false);
    }

    public function test_the_search_page_renders_the_same_filter(): void
    {
        $this->assertSame($this->renderedOrder(), $this->renderedOrder('/search?q=san+pham'));
    }

    public function test_the_panel_stays_usable_without_javascript(): void
    {
        // <details> carries the open/close state, and every category is a real link.
        $html = $this->get('/products')->assertOk()->getContent();
        $category = Category::where('name', 'Dụng cụ y tế')->first();

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
        $this->assertStringContainsString(
            route('products.index', ['category' => $category->MaDM]),
            $html
        );
    }

    public function test_the_header_dropdown_lists_the_same_categories_in_the_same_order(): void
    {
        $html = $this->get('/products')->assertOk()->getContent();

        preg_match_all('#nav-category-title">([^<]+)<#', $html, $nav);

        $navNames = array_map(
            fn (string $name) => html_entity_decode($name, ENT_QUOTES, 'UTF-8'),
            $nav[1]
        );

        // Both read Category::scopeBrowsable(), so the header and the sidebar cannot
        // disagree about which categories exist.
        $this->assertSame($this->renderedOrder(), $navNames);
    }

    public function test_the_header_dropdown_leaves_out_categories_with_nothing_in_stock(): void
    {
        Category::create(['name' => 'Danh mục rỗng']);

        // It used to list every category, so those rows opened an empty product page.
        $this->get('/')
            ->assertOk()
            ->assertDontSee('nav-category-title">Danh mục rỗng', false);
    }

    public function test_the_header_dropdown_carries_counts_depth_and_folded_names(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('nav-category-count">6<', $html);
        $this->assertStringContainsString('--depth: 100%', $html);
        $this->assertStringContainsString('data-folded="dung cu y te"', $html);
    }

    public function test_only_the_three_fullest_categories_carry_the_flame(): void
    {
        // A fourth category, or setUp's three would all be in the top three and a
        // broken cut-off would still pass.
        $this->stock('Chăm sóc cơ thể', 2);

        $html = $this->get('/products')->assertOk()->getContent();
        $sidebar = $this->catalogFilterMarkup($html);
        // The header dropdown renders above the sidebar, so what sits before the cut
        // is the nav's own markup.
        $header = substr($html, 0, strpos($html, 'catalog-filter-link is-all'));

        $this->assertSame(3, substr_count($sidebar, 'category-flame'));
        $this->assertSame(3, substr_count($header, 'category-flame'));

        // Bám vào tên: đếm đúng ba mà gắn sai hàng thì vẫn xanh.
        // aria-hidden vì thông tin thật là con số bên cạnh — lửa chỉ là trang trí.
        $this->assertStringContainsString(
            'Chăm sóc cơ thể<i class="bi bi-fire category-flame" aria-hidden="true"></i>',
            $sidebar
        );
        $this->assertStringNotContainsString('Dược mỹ phẩm<i', $sidebar);
        $this->assertStringNotContainsString('Dược mỹ phẩm<i', $header);
    }

    public function test_the_header_call_to_action_counts_the_whole_catalog(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('nav-categories-cta-count">10<', false);
    }
}
