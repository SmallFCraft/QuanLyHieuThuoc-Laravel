<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Xu hướng tìm kiếm được dựng từ chính từ khoá khách đã gõ, nên nó vừa là dữ liệu
 * vừa là chữ hiện trên trang chủ. Hai việc đó kéo theo hai nhóm test: đếm đúng, và
 * không để chữ rác lọt ra giao diện.
 */
class SearchTrendingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $category = Category::create(['name' => 'Vitamin & Khoáng chất']);

        Product::create([
            'MaDM' => $category->MaDM,
            'name' => 'Viên uống Vitamin C 1000mg DHC',
            'ingredients' => 'Vitamin C 1000mg',
            'price' => 120000,
            'expiration_date' => now()->addYears(2),
            'stock_quantity' => 40,
            'reorder_point' => 10,
            'batch_number' => 'LC-vitc',
        ]);
    }

    private function search(string $term, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('products.search', ['q' => $term] + $extra))->assertOk();
    }

    public function test_a_search_that_found_products_is_counted(): void
    {
        $this->search('vitamin c');

        $row = SearchQuery::sole();

        $this->assertSame('vitamin c', $row->term_folded);
        $this->assertSame(1, $row->hits);
    }

    public function test_the_same_term_typed_twice_counts_once_with_two_hits(): void
    {
        $this->search('vitamin c');
        $this->search('Vitamin  C');

        // Gộp trên dạng đã fold: hoa/thường và khoảng trắng đôi là cùng một xu hướng.
        $this->assertSame(1, SearchQuery::count());
        $this->assertSame(2, SearchQuery::sole()->hits);
    }

    public function test_a_search_that_found_nothing_is_not_counted(): void
    {
        // Gõ sai chính tả là nguồn rác lớn nhất. Không có kết quả thì không phải
        // xu hướng — đưa nó lên trang chủ là mời người sau vào một trang trống.
        $this->search('khong co thuoc nay dau');

        $this->assertSame(0, SearchQuery::count());
    }

    public function test_a_blacklisted_term_is_never_counted(): void
    {
        // Pill xu hướng là chữ khách gõ hiện trên trang chủ, nên nó đi qua đúng
        // blacklist mà chatbot dùng.
        Product::where('MaSP', '>', 0)->update(['name' => 'Thuốc ngu người', 'name_folded' => 'thuoc ngu nguoi']);

        $this->search('ngu');

        $this->assertSame(0, SearchQuery::count());
    }

    public function test_paging_through_results_does_not_inflate_the_count(): void
    {
        $this->search('vitamin c');

        // Trang 2 vượt trang cuối (1 kết quả / 10 mỗi trang) nên là 404, và cũng
        // không được đếm — một request không render kết quả thì không phải lượt tìm.
        $this->get(route('products.search', ['q' => 'vitamin c', 'page' => 2]))
            ->assertNotFound();

        $this->assertSame(1, SearchQuery::sole()->hits);
    }

    public function test_an_ajax_fragment_request_does_not_count(): void
    {
        $this->search('vitamin c');

        $this->getJson(route('products.search', ['q' => 'vitamin c']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $this->assertSame(1, SearchQuery::sole()->hits);
    }

    public function test_an_overlong_query_is_refused(): void
    {
        // Một đoạn văn dán vào ô tìm kiếm không phải từ khoá.
        $this->assertFalse(SearchQuery::record(str_repeat('vitamin ', 12)));
        $this->assertSame(0, SearchQuery::count());
    }

    public function test_trending_ranks_by_hits(): void
    {
        $this->seedTerm('collagen', 9);
        $this->seedTerm('khẩu trang', 4);
        $this->seedTerm('omega 3', 7);

        $trending = SearchQuery::trending(3)->all();

        $this->assertSame(['collagen', 'omega 3', 'khẩu trang'], $trending);
    }

    public function test_a_term_below_the_hit_floor_is_not_trending(): void
    {
        $this->seedTerm('chỉ một lượt', 1);

        $this->assertNotContains('chỉ một lượt', SearchQuery::trending()->all());
    }

    public function test_a_stale_term_falls_out_of_the_window(): void
    {
        $this->seedTerm('mùa cũ', 50, now()->subDays(45));

        $this->assertNotContains('mùa cũ', SearchQuery::trending()->all());
    }

    public function test_the_seed_list_fills_an_empty_log(): void
    {
        // Shop mới mở: panel trống trông như tính năng lỗi.
        $trending = SearchQuery::trending();

        $this->assertCount((int) config('search.trending.limit'), $trending);
        $this->assertSame(config('search.trending.seed'), $trending->all());
    }

    public function test_real_terms_come_before_the_seed_and_are_not_duplicated_by_it(): void
    {
        $this->seedTerm('Vitamin C', 12);

        $trending = SearchQuery::trending()->all();

        $this->assertSame('Vitamin C', $trending[0]);
        // "vitamin c" nằm trong seed; so trên dạng fold nên nó không hiện hai lần.
        $this->assertNotContains('vitamin c', $trending);
        $this->assertCount((int) config('search.trending.limit'), $trending);
    }

    public function test_recording_a_term_drops_the_trending_cache(): void
    {
        $this->seedTerm('collagen', 5);
        SearchQuery::trending();

        $this->seedTerm('collagen', 40);

        // Không xoá cache thì pill đứng yên tới 10 phút sau mỗi lượt tìm.
        $this->assertSame('collagen', SearchQuery::trending()->first());
    }

    private function seedTerm(string $term, int $hits, ?\Illuminate\Support\Carbon $when = null): void
    {
        SearchQuery::updateOrCreate(
            ['term_folded' => \App\Support\VietnameseText::fold($term)],
            ['term' => $term, 'hits' => $hits, 'last_searched_at' => $when ?? now()],
        );

        Cache::forget(SearchQuery::CACHE_KEY);
    }
}
