<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\LoSanPham;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThuThapDuLieuLongChauTest extends TestCase
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

    public function test_nhap_san_pham_co_gia_cung_danh_muc_va_so_lo(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')
            ->assertSuccessful();

        $sanPham = SanPham::sole();

        $this->assertSame('00005713', str_replace('LC-', '', $sanPham->so_lo));
        $this->assertSame(252000, $sanPham->price_vnd);
        $this->assertStringContainsString('Panadol Extra', $sanPham->ten);
        $this->assertSame('Thuốc giảm đau, hạ sốt, kháng viêm', $sanPham->danhMuc->ten);
        $this->assertSame(1, LoSanPham::count());
        $this->assertSame(1, DanhMuc::count());
    }

    public function test_san_pham_khong_con_bo_gia_bi_bo_qua(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertSuccessful();

        $this->assertSame(1, SanPham::count(), 'The prescription drug has no price to store.');
    }

    public function test_url_chet_khong_lam_he_luot_chay(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertExitCode(0);

        $this->assertSame(1, SanPham::count());
    }

    public function test_chay_hai_lan_cap_nhat_chu_khong_nhan_doi(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertSuccessful();
        $this->artisan('crawl:longchau --sitemap=thuoc')->assertSuccessful();

        $this->assertSame(1, SanPham::count());
        $this->assertSame(1, LoSanPham::count());
        $this->assertSame(1, DanhMuc::count());
    }

    public function test_tuy_chon_limit_gioi_han_so_trang_duoc_tai(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc --limit=1')->assertSuccessful();

        // Two requests: the sitemap, then the single product page.
        Http::assertSentCount(2);
    }

    public function test_chay_thu_khong_ghi_gi(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc --dry-run')
            ->expectsOutputToContain('không ghi vào cơ sở dữ liệu')
            ->assertSuccessful();

        $this->assertSame(0, SanPham::count());
        $this->assertSame(0, DanhMuc::count());
    }

    public function test_khoa_sitemap_khong_xac_dinh_bao_loi_rang_ro(): void
    {
        Http::fake();

        $this->artisan('crawl:longchau --sitemap=khong-co')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_sitemap_khong_the_truy_cap_bao_that_bai_thay_vi_bao_thanh_cong(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertFailed();

        $this->assertSame(0, SanPham::count());
    }

    public function test_cau_truc_site_doi_dung_lai_luot_chay(): void
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

        $this->assertSame(0, SanPham::count());
    }

    public function test_cache_danh_muc_gian_hang_duoc_lam_moi_sau_khi_nhap(): void
    {
        $this->fakeCatalog();

        cache()->put(DanhMuc::NAV_CACHE_KEY, 'stale', now()->addHour());

        $this->artisan('crawl:longchau --sitemap=thuoc')->assertSuccessful();

        $this->assertNull(cache()->get(DanhMuc::NAV_CACHE_KEY));
    }

    /**
     * A crawl that invents categories without saying so is how the catalog ended up
     * with both "Cơ - xương - khớp" and "Cơ xương khớp".
     */
    public function test_bao_cao_cac_danh_muc_phai_tao_moi(): void
    {
        $this->fakeCatalog();

        $this->artisan('crawl:longchau --sitemap=thuoc')
            ->expectsOutputToContain('1 danh mục mới được tạo')
            ->expectsOutputToContain('Thuốc giảm đau, hạ sốt, kháng viêm')
            ->assertSuccessful();
    }

    public function test_luot_chay_thu_hai_khong_bao_danh_muc_moi(): void
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
    public function test_hai_cach_dung_mot_phan_nhap_vao_mot_danh_muc_duy_nhat(): void
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

        $this->assertSame(1, DanhMuc::count());
        $this->assertSame('Cơ - xương - khớp', DanhMuc::sole()->ten);
    }
}
