<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
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
class BoLocDanhMucSanPhamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stock('Dụng cụ y tế', 6);
        $this->stock('Thuốc tiêu hoá & gan mật', 3);
        $this->stock('Dược mỹ phẩm', 1);
    }

    private function stock(string $categoryName, int $products): DanhMuc
    {
        $danhMuc = DanhMuc::create(['ten' => $categoryName]);

        for ($i = 0; $i < $products; $i++) {
            SanPham::create(['ma_dm' => $danhMuc->ma_dm,
                'ten' => "{$categoryName} sản phẩm {$i}",
                'gia' => 50000,
                'ngay_het_han' => now()->addYears(2),
                'so_luong_ton' => 10,
                'muc_canh_bao' => 5,
                'so_lo' => 'LC-' . md5($categoryName . $i),
            ]);
        }

        return $danhMuc;
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

    public function test_danh_muc_nhieu_ton_kho_nhat_dung_dau(): void
    {
        $this->assertSame(
            ['Dụng cụ y tế', 'Thuốc tiêu hoá & gan mật', 'Dược mỹ phẩm'],
            $this->renderedOrder()
        );
    }

    public function test_danh_muc_khong_con_hang_bi_bo_qua(): void
    {
        DanhMuc::create(['ten' => 'Danh mục rỗng']);

        $this->assertNotContains('Danh mục rỗng', $this->renderedOrder());
    }

    public function test_hang_tat_ca_dem_toan_bo_danh_muc_du_khi_dang_loc(): void
    {
        $danhMuc = DanhMuc::where('ten', 'Dược mỹ phẩm')->first();

        // Reading $products->total() here showed the filtered count, so the "all"
        // row claimed 1 product while sitting above 10.
        //
        // Anchored on catalog-filter-count: a bare '>10</span>' also matches the header
        // dropdown's own total, so the regression above would still pass.
        $this->get("/products?category={$danhMuc->ma_dm}")
            ->assertOk()
            ->assertSee('<span class="catalog-filter-count">10</span>', false);
    }

    public function test_moi_hang_co_do_sau_ton_kho_va_ten_khong_dau(): void
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

    public function test_tom_tat_mobile_neu_ten_danh_muc_dang_chon(): void
    {
        $danhMuc = DanhMuc::where('ten', 'Dụng cụ y tế')->first();

        $this->get("/products?category={$danhMuc->ma_dm}")
            ->assertOk()
            ->assertSee('catalog-filter__toggle-current">Dụng cụ y tế', false);
    }

    public function test_tom_tat_mobile_mac_dinh_la_tat_ca(): void
    {
        $this->get('/products')
            ->assertOk()
            ->assertSee('catalog-filter__toggle-current">Tất cả', false);
    }

    public function test_danh_muc_dang_chon_duoc_danh_dau_cho_cong_nghe_ho_tro(): void
    {
        $danhMuc = DanhMuc::where('ten', 'Dụng cụ y tế')->first();

        $this->get("/products?category={$danhMuc->ma_dm}")
            ->assertOk()
            ->assertSee('aria-current="page"', false);

        $this->get('/products')->assertOk()->assertSee('aria-current="page"', false);
    }

    public function test_trang_tim_kiem_render_cung_bo_loc(): void
    {
        $this->assertSame($this->renderedOrder(), $this->renderedOrder('/search?q=san+pham'));
    }

    public function test_bang_loc_van_dung_duoc_khong_can_javascript(): void
    {
        // <details> carries the open/close state, and every category is a real link.
        $html = $this->get('/products')->assertOk()->getContent();
        $danhMuc = DanhMuc::where('ten', 'Dụng cụ y tế')->first();

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
        $this->assertStringContainsString(
            route('san-pham.index', ['category' => $danhMuc->ma_dm]),
            $html
        );
    }

    public function test_menu_dau_trang_liet_ke_cung_danh_muc_cung_thu_tu(): void
    {
        $html = $this->get('/products')->assertOk()->getContent();

        preg_match_all('#nav-category-title">([^<]+)<#', $html, $nav);

        $navNames = array_map(
            fn (string $name) => html_entity_decode($name, ENT_QUOTES, 'UTF-8'),
            $nav[1]
        );

        // Both read DanhMuc::scopeBrowsable(), so the header and the sidebar cannot
        // disagree about which categories exist.
        $this->assertSame($this->renderedOrder(), $navNames);
    }

    public function test_menu_dau_trang_bo_qua_danh_muc_khong_con_hang(): void
    {
        DanhMuc::create(['ten' => 'Danh mục rỗng']);

        // It used to list every category, so those rows opened an empty product page.
        $this->get('/')
            ->assertOk()
            ->assertDontSee('nav-category-title">Danh mục rỗng', false);
    }

    public function test_menu_dau_trang_co_so_dem_do_sau_va_ten_khong_dau(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('nav-category-count">6<', $html);
        $this->assertStringContainsString('--depth: 100%', $html);
        $this->assertStringContainsString('data-folded="dung cu y te"', $html);
    }

    public function test_chi_ba_danh_muc_day_hang_nhat_co_bieu_tuong_lua(): void
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

    public function test_loi_keu_goi_dau_trang_dem_toan_bo_danh_muc(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('nav-categories-cta-count">10<', false);
    }
}
