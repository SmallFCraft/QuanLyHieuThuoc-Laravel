<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ngăn bot cào vét sạch tài nguyên hosting: chặn trang phân trang vượt ngưỡng (404),
 * đánh dấu noindex cho trang phân trang, và chặn crawler AI trong robots.txt.
 */
class CrawlerBandwidthContainmentTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'Thuốc giảm đau']);

        // 14 sản phẩm = 2 trang (PER_PAGE = 10)
        for ($i = 0; $i < 14; $i++) {
            Product::create([
                'MaDM' => $this->category->MaDM,
                'name' => "Sản phẩm thử nghiệm {$i}",
                'price' => 20000,
                'expiration_date' => now()->addYear(),
                'stock_quantity' => 10,
                'reorder_point' => 2,
                'batch_number' => "BATCH-CRAWL-{$i}",
            ]);
        }
    }

    public function test_a_page_number_beyond_the_last_page_returns_404(): void
    {
        // 14 sản phẩm / 10 = 2 trang. Trang 3 và 99999 phải 404, không trả HTML 300KB lặp vô tận.
        $this->get('/products?page=3')->assertNotFound();
        $this->get('/products?page=99999')->assertNotFound();
    }

    public function test_out_of_range_page_returns_404_on_every_paginated_storefront_route(): void
    {
        // Ba đường phân trang cùng một lỗ: chỉ chặn index() thì hai đường còn lại vẫn
        // trả 200 đầy đủ và crawler vẫn sinh URL vô hạn.
        $this->get('/products?page=99999')->assertNotFound();
        $this->get("/products/category/{$this->category->MaDM}?page=99999")->assertNotFound();
        $this->get('/search?q=thuoc&page=99999')->assertNotFound();
    }

    public function test_an_unparseable_integer_page_returns_404(): void
    {
        // Paginator ép số tràn về trang 1 nên so sánh một mình không bắt được; mỗi giá
        // trị vẫn là một URL 200 ~300KB riêng.
        $this->get('/products?page=99999999999999999999')->assertNotFound();
        $this->get('/products?page=02')->assertNotFound();
    }

    public function test_a_missing_category_filter_returns_404_instead_of_an_empty_page(): void
    {
        // where('MaDM', ...) trả danh sách rỗng 200 cho id không tồn tại, nên mỗi số
        // tự đặt là một URL indexable.
        $this->get('/products?category=999999')->assertNotFound();
    }

    public function test_an_array_query_parameter_is_rejected(): void
    {
        // ?category[]=1 vỡ query builder (500, ~1.3MB stack trace trong APP_DEBUG).
        $this->get('/products?category[]=1')->assertStatus(400);
        $this->get('/products?page[]=1')->assertStatus(400);
    }

    public function test_a_search_term_on_the_catalog_collapses_its_canonical(): void
    {
        // /products?q= là bản sao của /search; mỗi chuỗi tìm là một URL indexable mới.
        $html = $this->get('/products?q=thuoc')->assertOk()->getContent();

        $canonical = html_entity_decode($this->canonicalHref($html), ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('q=', $canonical);
    }

    public function test_an_ajax_request_beyond_the_last_page_returns_404(): void
    {
        $this->getJson('/products?page=99999', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertNotFound();
    }

    public function test_in_range_pages_and_page_one_still_render_ok(): void
    {
        $this->get('/products')->assertOk();
        $this->get('/products?page=1')->assertOk();
        $this->get('/products?page=2')->assertOk();
    }

    public function test_non_positive_or_non_integer_page_inputs_return_404(): void
    {
        // Paginator ép -5, abc, 0, 2.5 về trang 1 — nhưng mỗi giá trị là một URL 200
        // mới với cùng nội dung trang 1. Link phân trang thật không bao giờ sinh chúng.
        $this->get('/products?page=-5')->assertNotFound();
        $this->get('/products?page=abc')->assertNotFound();
        $this->get('/products?page=0')->assertNotFound();
        $this->get('/products?page=2.5')->assertNotFound();
    }

    public function test_junk_query_string_with_out_of_range_page_returns_404(): void
    {
        // Tái hiện chính xác chuỗi bot cào trong log. `amp;page` là một tham số riêng
        // và vô hại; cái phải 404 là `page=7` trên catalog chỉ có 2 trang.
        $this->get('/products?amp;page=74&category=' . $this->category->MaDM . '&page=7')
            ->assertNotFound();

        // Và `amp;page` một mình thì vô hiệu: key đó không phải `page`, paginator bỏ
        // qua nên vẫn là trang 1 — giữ nguyên để biết không chặn nhầm tham số theo dõi.
        $this->get('/products?amp;page=99999')->assertOk();
    }

    public function test_a_404_does_not_record_a_search_query(): void
    {
        // recordSearch() đứng sau abort_if. Đảo thứ tự thì mỗi lượt bot cào ghi một
        // dòng vào bảng xu hướng, mà suite vẫn xanh nếu chỉ kiểm status.
        SearchQuery::query()->delete();

        $this->get('/products?q=thuoc&page=99999')->assertNotFound();

        $this->assertSame(0, SearchQuery::query()->count());
    }

    public function test_paginated_catalog_emits_noindex_and_canonical_without_page(): void
    {
        $html = $this->get('/products?page=2')->assertOk()->getContent();

        // follow chứ không nofollow: crawler vẫn phải đi tiếp tới trang 1 để thấy sản phẩm.
        $this->assertSame('noindex, follow', $this->robotsMeta($html));

        // Canonical phải gập về trang gốc, không tự tham chiếu page=2.
        $canonical = html_entity_decode($this->canonicalHref($html), ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('page=', $canonical);
    }

    public function test_a_paginated_category_also_collapses_its_canonical(): void
    {
        $html = $this->get('/products?category=' . $this->category->MaDM . '&page=2')
            ->assertOk()
            ->getContent();

        $this->assertSame('noindex, follow', $this->robotsMeta($html));

        // Bộ lọc danh mục vẫn là một phần danh tính trang; chỉ `page` bị bỏ.
        $canonical = html_entity_decode($this->canonicalHref($html), ENT_QUOTES, 'UTF-8');
        $this->assertStringNotContainsString('page=', $canonical);
        $this->assertStringContainsString('category=' . $this->category->MaDM, $canonical);
    }

    public function test_first_page_of_catalog_remains_index_follow(): void
    {
        $html = $this->get('/products')->assertOk()->getContent();

        $this->assertSame('index, follow', $this->robotsMeta($html));
    }

    public function test_page_one_explicitly_named_is_still_indexable(): void
    {
        // page=1 là cùng một tài liệu với /products — không được noindex.
        $html = $this->get('/products?page=1')->assertOk()->getContent();

        $this->assertSame('index, follow', $this->robotsMeta($html));
    }

    private function robotsMeta(string $html): string
    {
        return $this->headTag($html, '#<meta name="robots" content="([^"]*)"#', 'meta robots');
    }

    private function canonicalHref(string $html): string
    {
        return $this->headTag($html, '#<link rel="canonical" href="([^"]*)"#', 'canonical');
    }

    /** Hai helper trên cùng một việc: tìm thẻ trong <head> và trả giá trị thuộc tính. */
    private function headTag(string $html, string $pattern, string $label): string
    {
        $this->assertMatchesRegularExpression($pattern, $html, "Trang phải có thẻ {$label}.");

        preg_match($pattern, $html, $matches);

        return $matches[1];
    }

    public function test_robots_txt_disallows_each_ai_crawler_and_preserves_wildcard(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        // Kiểm từng token: chỉ kiểm "Disallow: /" có mặt thì xoá hết tên crawler
        // vẫn xanh, vì wildcard cũng không được rỗng Disallow.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $robots)), fn ($l) => $l !== ''));

        foreach (['GPTBot', 'Google-Extended', 'CCBot', 'anthropic-ai', 'ClaudeBot', 'Bytespider', 'Amazonbot', 'Meta-ExternalAgent'] as $agent) {
            $this->assertContains(
                "User-agent: {$agent}",
                $lines,
                "{$agent} phải bị chặn trong robots.txt."
            );
        }

        // Cả nhóm dùng chung một Disallow (RFC 9309 §2.2.1), và dòng đó nằm sau
        // mọi tên crawler trong nhóm — sai thứ tự thì nhóm mất hiệu lực.
        $grouped = array_search('Disallow: /', $lines, true);
        $this->assertNotFalse($grouped, 'Nhóm crawler AI phải có một Disallow: /.');

        foreach (array_slice($lines, 0, $grouped) as $line) {
            $this->assertTrue(
                str_starts_with($line, 'User-agent:') || str_starts_with($line, '#'),
                "Dòng {$line} phải đứng trong khối User-agent."
            );
        }

        // Wildcard vẫn cho phép mọi crawler còn lại, và là khối cuối.
        $this->assertContains('User-agent: *', $lines, 'Wildcard phải còn nguyên.');
        $this->assertSame('Disallow:', end($lines), 'Khối wildcard phải là khối cuối cùng.');
    }
}
