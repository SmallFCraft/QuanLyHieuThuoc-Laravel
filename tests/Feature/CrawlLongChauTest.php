<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrawlLongChauTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('crawler.longchau.delay_ms', 0);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/fixtures/longchau/{$name}"));
    }

    private function sitemap(array $urls): string
    {
        $entries = collect($urls)
            ->map(fn (string $url) => "<url><loc>{$url}</loc></url>")
            ->implode('');

        return '<?xml version="1.0" encoding="UTF-8"?><urlset>' . $entries . '</urlset>';
    }

    /**
     * One sitemap holding a priced product, an unpriced prescription drug and a
     * dead URL — the mix the live site actually returns.
     */
    private function fakeCatalog(): void
    {
        Http::fake([
            '*/sitemap_thuoc.xml' => Http::response($this->sitemap([
                'https://nhathuoclongchau.com.vn/thuoc',
                'https://nhathuoclongchau.com.vn/thuoc/panadol-extra-1663.html',
                'https://nhathuoclongchau.com.vn/thuoc/bisoloc-25mg-4482.html',
                'https://nhathuoclongchau.com.vn/thuoc/khong-ton-tai-1.html',
            ])),
            '*/panadol-extra*' => Http::response($this->fixture('panadol.html')),
            '*/bisoloc-25mg*' => Http::response($this->fixture('bisoloc-no-price.html')),
            '*/khong-ton-tai*' => Http::response('', 404),
        ]);
    }

    public function test_it_imports_priced_products_with_their_category_and_batch(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')
            ->assertSuccessful();

        $product = Product::sole();

        $this->assertSame('00005713', str_replace('LC-', '', $product->batch_number));
        $this->assertSame(252000, $product->price_vnd);
        $this->assertStringContainsString('Panadol Extra', $product->name);
        $this->assertSame('Thuốc giảm đau, hạ sốt, kháng viêm', $product->category->name);
        $this->assertSame(1, ProductBatch::count());
        $this->assertSame(1, Category::count());
    }

    public function test_products_without_a_published_price_are_left_out(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertSuccessful();

        $this->assertSame(1, Product::count(), 'The prescription drug has no price to store.');
    }

    public function test_a_dead_url_does_not_fail_the_run(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertExitCode(0);

        $this->assertSame(1, Product::count());
    }

    public function test_running_it_twice_updates_rather_than_duplicates(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertSuccessful();
        $this->artisan('crawl:longchau --sitemap=thuoc')->assertSuccessful();

        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductBatch::count());
        $this->assertSame(1, Category::count());
    }

    public function test_the_limit_option_caps_the_number_of_pages_fetched(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc --limit=1')->assertSuccessful();

        // Two requests: the sitemap, then the single product page.
        Http::assertSentCount(2);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc --dry-run')
            ->expectsOutputToContain('không ghi vào cơ sở dữ liệu')
            ->assertSuccessful();

        $this->assertSame(0, Product::count());
        $this->assertSame(0, Category::count());
    }

    public function test_an_unknown_sitemap_key_fails_loudly(): void
    {
        Http::fake();

        $this->artisan('crawl:longchau --sitemap=khong-co')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_an_unreachable_sitemap_fails_instead_of_reporting_success(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertFailed();

        $this->assertSame(0, Product::count());
    }

    public function test_a_site_structure_change_aborts_the_run(): void
    {
        config()->set('crawler.longchau.missing_json_guard_floor', 3);

        Http::fake([
            '*/sitemap_thuoc.xml' => Http::response($this->sitemap([
                'https://nhathuoclongchau.com.vn/thuoc/a-1.html',
                'https://nhathuoclongchau.com.vn/thuoc/b-2.html',
                'https://nhathuoclongchau.com.vn/thuoc/c-3.html',
                'https://nhathuoclongchau.com.vn/thuoc/d-4.html',
            ])),
            '*' => Http::response('<html><body>client rendered now</body></html>'),
        ]);

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertFailed();

        $this->assertSame(0, Product::count());
    }

    public function test_the_storefront_category_cache_is_refreshed_after_an_import(): void
    {
        $this->fakeCatalog();

        cache()->put(Category::NAV_CACHE_KEY, 'stale', now()->addHour());

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertSuccessful();

        $this->assertNull(cache()->get(Category::NAV_CACHE_KEY));
    }

    /**
     * A crawl that invents categories without saying so is how the catalog ended up
     * with both "Cơ - xương - khớp" and "Cơ xương khớp".
     */
    public function test_it_reports_the_categories_it_had_to_create(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')
            ->expectsOutputToContain('1 danh mục mới được tạo')
            ->expectsOutputToContain('Thuốc giảm đau, hạ sốt, kháng viêm')
            ->assertSuccessful();
    }

    public function test_a_second_run_reports_no_new_categories(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertSuccessful();

        $this->artisan('crawl:longchau --sitemap=thuoc')
            ->doesntExpectOutputToContain('danh mục mới được tạo')
            ->assertSuccessful();
    }

    /**
     * Long Châu spells the same section differently per branch: "Cơ - xương - khớp"
     * under Thuốc, "Cơ xương khớp" under Thực phẩm chức năng. Both must land in one
     * storefront category.
     */
    public function test_two_spellings_of_one_section_import_into_a_single_category(): void
    {
        $panadol = $this->fixture('panadol.html');

        $spelt = function (string $categoryName) use ($panadol) {
            return str_replace(
                'Thuốc giảm đau, hạ sốt, kháng viêm',
                $categoryName,
                $panadol
            );
        };

        Http::fake([
            '*/sitemap_thuoc.xml' => Http::response($this->sitemap([
                'https://nhathuoclongchau.com.vn/thuoc/mot-1.html',
                'https://nhathuoclongchau.com.vn/thuc-pham-chuc-nang/hai-2.html',
            ])),
            '*/mot-1*' => Http::response($spelt('Cơ - xương - khớp')),
            '*/hai-2*' => Http::response(str_replace(
                '"sku":"00005713"',
                '"sku":"00009999"',
                $spelt('Cơ xương khớp')
            )),
        ]);

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertSuccessful();

        $this->assertSame(1, Category::count());
        $this->assertSame('Cơ - xương - khớp', Category::sole()->name);
    }
}
