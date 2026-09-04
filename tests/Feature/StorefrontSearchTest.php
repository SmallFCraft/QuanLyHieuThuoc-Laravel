<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalog screen, the results page and the autocomplete endpoint all answer a
 * customer's query, so they share one scope and must agree.
 *
 * Written against a crawled catalog: thousands of rows whose `usage` text averages
 * 1,400 characters. At that size, matching loosely returns most of the shop —
 * "thuốc ho cho trẻ" matched 2,875 of 3,132 products before this.
 */
class StorefrontSearchTest extends TestCase
{
    use RefreshDatabase;

    private Category $painRelief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->painRelief = Category::create(['name' => 'Thuốc giảm đau, hạ sốt']);

        $this->make('Viên nén Paracetamol Stada 500mg', 'Paracetamol 500mg', 'Điều trị các cơn đau đầu, đau lưng.');
        $this->make('Viên nén Panadol Extra đỏ GSK', 'Caffeine 65mg, Paracetamol 500mg', 'Giảm đau đầu, hạ sốt.');
        $this->make('Thuốc Alaxan FR United trị đau đầu', 'Ibuprofen 200mg', 'Giảm đau nhanh, thay cho paracetamol.');
        $this->make('Siro ho Prospan cho trẻ', 'Cao khô lá thường xuân', 'Điều trị ho ở trẻ em.');
        $this->make('Kem dưỡng ẩm Ceradan', 'Ceramide', 'Dưỡng ẩm cho da khô.');
    }

    private function make(string $name, string $ingredients, string $usage): Product
    {
        return Product::create([
            'MaDM' => $this->painRelief->MaDM,
            'name' => $name,
            'ingredients' => $ingredients,
            'usage' => $usage,
            'price' => 50000,
            'expiration_date' => now()->addYears(2),
            'stock_quantity' => 40,
            'reorder_point' => 10,
            'batch_number' => 'LC-' . md5($name),
        ]);
    }

    /** @return array<string> */
    private function names(string $term): array
    {
        return Product::query()
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->storefrontSearch($term)
            ->orderBySearchRelevance($term)
            ->pluck('name')
            ->all();
    }

    public function test_an_accent_free_query_finds_accented_products(): void
    {
        // The MySQL collation folds tone marks but not "đ", so this returned nothing.
        $this->assertSame($this->names('đau đầu'), $this->names('dau dau'));
        $this->assertNotEmpty($this->names('dau dau'));
    }

    public function test_every_word_must_match(): void
    {
        $results = $this->names('ho tre');

        // ORing the words would also return the paracetamol products, whose usage
        // text mentions neither.
        $this->assertSame(['Siro ho Prospan cho trẻ'], $results);
    }

    public function test_a_query_matching_nothing_returns_nothing(): void
    {
        $this->assertSame([], $this->names('khang sinh amoxicillin'));
    }

    public function test_products_naming_the_term_rank_above_products_merely_listing_it(): void
    {
        $results = $this->names('paracetamol');

        // Panadol contains paracetamol but does not say so in its name.
        $this->assertSame('Viên nén Paracetamol Stada 500mg', $results[0]);
        $this->assertSame('Viên nén Panadol Extra đỏ GSK', end($results));
    }

    public function test_a_name_starting_with_the_term_ranks_first(): void
    {
        $this->make('Thuốc ho bổ phế Panadol phối hợp', 'Panadol', 'Ho.');

        $this->assertSame('Viên nén Panadol Extra đỏ GSK', $this->names('panadol')[0]);
    }

    public function test_an_ingredient_search_finds_products_that_do_not_name_it(): void
    {
        $this->assertContains('Viên nén Panadol Extra đỏ GSK', $this->names('caffeine'));
    }

    public function test_usage_prose_alone_does_not_match(): void
    {
        // usage averages 1,400 characters on crawled rows; searching it turns almost
        // any symptom word into a catalog-wide match.
        $this->assertNotContains('Kem dưỡng ẩm Ceradan', $this->names('da kho'));
    }

    public function test_a_wildcard_in_a_storefront_query_is_a_word_separator(): void
    {
        // Folding strips punctuation, so "%" cannot reach the LIKE — it splits the
        // query instead. "pa%ol" is therefore the two words "pa" and "ol".
        $this->assertSame(Product::searchWords('pa%ol'), ['pa', 'ol']);
        $this->assertSame($this->names('pa ol'), $this->names('pa%ol'));
    }

    public function test_a_wildcard_stays_literal_in_the_admin_search(): void
    {
        // The admin scope matches raw, unfolded text, so it is the one that needs
        // the wildcards escaped.
        $this->assertSame(0, Product::search('Paracetamo%')->count());
        $this->assertSame(1, Product::search('Paracetamol')->count());
    }

    public function test_a_bare_wildcard_is_treated_as_an_empty_query(): void
    {
        // "%" folds away to nothing, which means "no filter" — the same as an
        // empty box, not a filter that silently matches everything.
        $this->assertCount(5, $this->names('%'));
    }

    public function test_a_blank_query_leaves_the_catalog_unfiltered(): void
    {
        $this->assertCount(5, $this->names(''));
        $this->assertCount(5, $this->names('   '));
    }

    public function test_the_results_page_and_the_catalog_screen_agree(): void
    {
        $fromSearch = $this->get(route('products.search', ['q' => 'paracetamol']))
            ->assertOk()
            ->viewData('products')
            ->pluck('name')
            ->all();

        $fromCatalog = $this->get(route('products.index', ['q' => 'paracetamol']))
            ->assertOk()
            ->viewData('products')
            ->pluck('name')
            ->all();

        $this->assertSame($fromSearch, $fromCatalog);
        $this->assertSame($this->names('paracetamol'), $fromSearch);
    }

    public function test_the_search_term_survives_pagination_links(): void
    {
        // Đủ hơn một trang mới có link phân trang để mà kiểm: catalog trong setUp chỉ có
        // 2 sản phẩm khớp "paracetamol", dưới PER_PAGE = 10, nên trang không hề render
        // link nào và một assertSee('paracetamol') trần chỉ khớp giá trị ô tìm kiếm.
        for ($i = 0; $i < 10; $i++) {
            $this->make("Viên nén Paracetamol phụ {$i}", 'Paracetamol 500mg', 'Giảm đau.');
        }

        // Từ khoá phải nằm trong chính href của trang 2, không chỉ đâu đó trên trang:
        // 'q=paracetamol' còn xuất hiện ở ô tìm kiếm và link "xem tất cả".
        $html = $this->get(route('products.search', ['q' => 'paracetamol']))
            ->assertOk()
            ->getContent();

        preg_match_all('#href="([^"]*page=2[^"]*)"#', $html, $matches);

        $this->assertNotEmpty($matches[1], 'Trang kết quả phải có link phân trang.');

        foreach (array_unique($matches[1]) as $href) {
            $this->assertStringContainsString('q=paracetamol', html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
        }
    }

    public function test_the_header_search_box_keeps_the_current_term(): void
    {
        // The box used to clear itself on the results page, so refining a search
        // meant retyping it.
        $this->get(route('products.search', ['q' => 'paracetamol']))
            ->assertOk()
            ->assertSee('id="header-search" name="q" value="paracetamol"', false);
    }

    public function test_an_empty_result_names_the_term_and_offers_a_way_back(): void
    {
        $this->get(route('products.search', ['q' => 'khong-co-thuoc-nay']))
            ->assertOk()
            ->assertSee('khong-co-thuoc-nay')
            ->assertSee(route('products.index'), false);
    }

    public function test_one_search_box_ships_per_page_and_works_without_javascript(): void
    {
        // Ô tìm kiếm của header là ô duy nhất trong app. Trước đây có ba ô cùng post
        // một `q` — header, trang chủ, trang catalog — và cần JS ẩn bớt để khỏi hiện
        // hai ô một lúc; đếm ở đây để không lặng lẽ mọc lại ô thứ hai.
        $action = 'action="' . route('products.search') . '" method="GET"';

        foreach ([route('home'), route('products.index'), route('products.search', ['q' => 'paracetamol'])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, $action), "Trang {$url} phải có đúng một form tìm kiếm.");
            $this->assertStringContainsString('id="header-search" name="q"', $html);
            $this->assertStringNotContainsString('data-page-search', $html);
        }
    }

    public function test_the_header_search_box_is_outside_the_collapsed_menu(): void
    {
        // Trên điện thoại ô tìm kiếm phải nằm ngay hàng header, không nằm trong burger:
        // #navbarNav là .collapse nên nội dung bên trong bị ẩn tới khi bấm nút.
        $html = $this->get(route('home'))->assertOk()->getContent();

        $box = strpos($html, 'id="header-search"');
        $collapse = strpos($html, 'id="navbarNav"');

        $this->assertNotFalse($box);
        $this->assertNotFalse($collapse);
        $this->assertLessThan($collapse, $box, 'Ô tìm kiếm phải đứng trước #navbarNav.');
    }
}
