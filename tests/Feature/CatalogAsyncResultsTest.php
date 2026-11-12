<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catalog trả kết quả theo từng khối để bộ lọc danh mục và phân trang không phải
 * tải lại trang. Chọn một danh mục ở cuối danh sách rồi bị đưa về đầu trang là lý
 * do có nhánh này.
 */
class CatalogAsyncResultsTest extends TestCase
{
    use RefreshDatabase;

    private DanhMuc $danhMuc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->danhMuc = DanhMuc::create(['ten' => 'Thuốc hô hấp']);

        for ($i = 0; $i < 14; $i++) {
            SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
                'ten' => "Thuốc hô hấp số {$i}",
                'gia' => 45000,
                'ngay_het_han' => now()->addYears(2),
                'so_luong_ton' => 8,
                'muc_canh_bao' => 4,
                'so_lo' => 'LC-' . md5("respiratory-{$i}"),
            ]);
        }
    }

    public function test_the_catalog_lists_ten_products_a_page(): void
    {
        $response = $this->get('/products')->assertOk();

        $this->assertSame(10, $response->viewData('products')->count());
        $this->assertSame(14, $response->viewData('products')->total());
    }

    public function test_search_and_the_category_page_use_the_same_page_size(): void
    {
        $this->assertSame(
            10,
            $this->get('/search?q=thuoc+ho+hap')->assertOk()->viewData('products')->count()
        );

        $this->assertSame(
            10,
            $this->get("/products/category/{$this->danhMuc->ma_dm}")->assertOk()->viewData('products')->count()
        );
    }

    public function test_an_ajax_request_returns_only_the_results_block(): void
    {
        $response = $this->getJson('/products', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $response->assertJsonStructure(['html', 'total']);
        $this->assertSame(14, $response->json('total'));

        $html = $response->json('html');

        // Khối kết quả, không phải cả trang: không có header, sidebar hay chatbot.
        $this->assertStringContainsString('catalog-product-card', $html);
        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('catalog-filter-link', $html);
    }

    public function test_the_fragment_honours_the_category_filter_and_the_page_number(): void
    {
        $other = DanhMuc::create(['ten' => 'Thuốc tiêu hoá']);

        SanPham::create(['ma_dm' => $other->ma_dm,
            'ten' => 'Thuốc tiêu hoá đơn lẻ',
            'gia' => 30000,
            'ngay_het_han' => now()->addYears(2),
            'so_luong_ton' => 5,
            'muc_canh_bao' => 2,
            'so_lo' => 'LC-digestive',
        ]);

        $filtered = $this->getJson(
            "/products?category={$other->ma_dm}",
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertOk();

        $this->assertSame(1, $filtered->json('total'));
        $this->assertStringContainsString('Thuốc tiêu hoá đơn lẻ', $filtered->json('html'));

        // Sản phẩm vừa thêm nâng tổng lên 15, nên trang 2 còn 5 thẻ.
        $secondPage = $this->getJson('/products?page=2', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();

        $this->assertSame(5, substr_count($secondPage->json('html'), 'catalog-product-card'));
    }

    public function test_a_plain_request_still_renders_the_whole_page(): void
    {
        // Không có JS thì mọi danh mục vẫn là link thật dẫn tới trang đầy đủ.
        $this->get("/products?category={$this->danhMuc->ma_dm}")
            ->assertOk()
            ->assertViewIs('products.index')
            ->assertSee('data-catalog-results', false)
            ->assertSee('catalog-filter-link', false);
    }
}
