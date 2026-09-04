<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Panel tìm kiếm được nhúng vào trang thay vì gọi thêm một endpoint, nên nội dung của
 * nó là HTML server render và test được ở đây. Lịch sử tìm kiếm nằm ở localStorage
 * nên không thuộc phạm vi test này.
 */
class SearchPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $category = Category::create(['name' => 'Thuốc giảm đau, hạ sốt']);

        foreach (range(1, 3) as $i) {
            Product::create([
                'MaDM' => $category->MaDM,
                'name' => "Viên nén Paracetamol số {$i}",
                'price' => 50000 * $i,
                'expiration_date' => now()->addYears(2),
                'stock_quantity' => 20,
                'reorder_point' => 5,
                'batch_number' => "LC-panel-{$i}",
                'views_count' => 100 - $i,
            ]);
        }
    }

    public function test_the_panel_template_ships_on_every_storefront_page(): void
    {
        // <template>, không phải <div>: nội dung là nguồn để JS clone, không được
        // render thẳng vào layout.
        foreach ([route('home'), route('products.index')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('<template id="search-panel-data">', false);
        }
    }

    public function test_the_panel_carries_trending_terms_top_categories_and_popular_products(): void
    {
        SearchQuery::updateOrCreate(
            ['term_folded' => 'thuoc ho tre em'],
            ['term' => 'thuốc ho trẻ em', 'hits' => 12, 'last_searched_at' => now()],
        );
        Cache::forget(SearchQuery::CACHE_KEY);

        $response = $this->get(route('home'))->assertOk();

        $response->assertSee(__('messages.search_trending'))
            ->assertSee('thuốc ho trẻ em')
            ->assertSee(__('messages.search_top_categories'))
            ->assertSee(__('messages.search_most_viewed'));

        // Chỉ tính phần trong <template>: trang chủ tự render tên sản phẩm ở hai lưới
        // "mới ra mắt" và "nổi bật", nên quét cả trang thì xoá hẳn khối panel vẫn xanh.
        $panel = $this->panelMarkup($response->getContent());

        // Xem nhiều nhất là số 1 (views_count 99).
        $this->assertStringContainsString('Viên nén Paracetamol số 1', $panel);
    }

    /** Phần HTML từ thẻ <template> của panel trở đi. */
    private function panelMarkup(string $html): string
    {
        $start = strpos($html, '<template id="search-panel-data">');

        $this->assertNotFalse($start, 'Panel template không có trên trang.');

        return substr($html, $start);
    }

    public function test_the_panel_shows_at_most_three_popular_products(): void
    {
        foreach (range(4, 8) as $i) {
            Product::create([
                'MaDM' => Category::sole()->MaDM,
                'name' => "Viên nén Paracetamol số {$i}",
                'price' => 10000,
                'expiration_date' => now()->addYears(2),
                'stock_quantity' => 5,
                'reorder_point' => 2,
                'batch_number' => "LC-panel-{$i}",
                'views_count' => 10,
            ]);
        }

        $panel = $this->panelMarkup($this->get(route('home'))->assertOk()->getContent());

        $this->assertSame(3, substr_count($panel, 'class="suggestion-item product-item"'));
    }

    public function test_an_out_of_stock_product_is_not_offered_in_the_panel(): void
    {
        // Panel là một lối vào bán hàng: dẫn vào một sản phẩm hết hàng là ngõ cụt.
        // Không Cache::flush() ở đây — cache phải được chính lượt save() dọn, nếu không
        // thì test chỉ chứng minh query đúng chứ không chứng minh cache hết hạn đúng.
        $this->get(route('home'))->assertOk();

        Product::each(fn (Product $product) => $product->forceFill(['stock_quantity' => 0])->save());

        $panel = $this->panelMarkup($this->get(route('home'))->assertOk()->getContent());

        $this->assertStringNotContainsString('class="suggestion-item product-item"', $panel);
        $this->assertStringNotContainsString(__('messages.search_most_viewed'), $panel);
    }

    public function test_the_trending_pills_are_real_links_so_they_work_without_javascript(): void
    {
        SearchQuery::updateOrCreate(
            ['term_folded' => 'collagen'],
            ['term' => 'collagen', 'hits' => 20, 'last_searched_at' => now()],
        );
        Cache::forget(SearchQuery::CACHE_KEY);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('products.search', ['q' => 'collagen']), false);
    }

    public function test_the_panel_language_strings_reach_the_browser(): void
    {
        // JS đọc chuỗi từ window.PharmacySearchLang; thiếu là panel hiện chuỗi dự phòng
        // tiếng Việt cứng trong JS, tức là hai nguồn chữ.
        //
        // So trên dạng đã json_encode: @json thoát tiếng Việt thành \uXXXX và dấu " thành
        // " (JSON_HEX_QUOT), nên so với chuỗi thô sẽ không khớp dù chữ đã có mặt.
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        $encoded = fn (string $value) => trim(json_encode($value, $flags), '"');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('window.PharmacySearchLang', false)
            ->assertSee($encoded(__('messages.search_history_clear')), false)
            ->assertSee($encoded(__('messages.search_view_all')), false)
            ->assertSee($encoded(config('search.placeholder_rotation')[0]), false);
    }

    public function test_the_suggestions_endpoint_contract_is_unchanged(): void
    {
        // Panel dùng chung container với khối gợi ý; hợp đồng JSON không được đổi.
        $this->getJson(route('products.suggestions', ['q' => 'paracetamol']))
            ->assertOk()
            ->assertJsonStructure([
                'products' => [['MaSP', 'name', 'category', 'price', 'image_url', 'url']],
                'categories',
            ]);
    }
}
