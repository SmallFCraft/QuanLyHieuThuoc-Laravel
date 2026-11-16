<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
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
class TimKiemCuaHangTest extends TestCase
{
    use RefreshDatabase;

    private DanhMuc $painRelief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->painRelief = DanhMuc::create(['ten' => 'Thuốc giảm đau, hạ sốt']);

        $this->make('Viên nén Paracetamol Stada 500mg', 'Paracetamol 500mg', 'Điều trị các cơn đau đầu, đau lưng.');
        $this->make('Viên nén Panadol Extra đỏ GSK', 'Caffeine 65mg, Paracetamol 500mg', 'Giảm đau đầu, hạ sốt.');
        $this->make('Thuốc Alaxan FR United trị đau đầu', 'Ibuprofen 200mg', 'Giảm đau nhanh, thay cho paracetamol.');
        $this->make('Siro ho Prospan cho trẻ', 'Cao khô lá thường xuân', 'Điều trị ho ở trẻ em.');
        $this->make('Kem dưỡng ẩm Ceradan', 'Ceramide', 'Dưỡng ẩm cho da khô.');
    }

    private function make(string $name, string $ingredients, string $usage): SanPham
    {
        return SanPham::create(['ma_dm' => $this->painRelief->ma_dm,
            'ten' => $name,
            'thanh_phan' => $ingredients,
            'cach_dung' => $usage,
            'gia' => 50000,
            'ngay_het_han' => now()->addYears(2),
            'so_luong_ton' => 40,
            'muc_canh_bao' => 10,
            'so_lo' => 'LC-' . md5($name),
        ]);
    }

    /** @return array<string> */
    private function names(string $term): array
    {
        return SanPham::query()
            ->notExpired()
            ->where('so_luong_ton', '>', 0)
            ->storefrontSearch($term)
            ->orderBySearchRelevance($term)
            ->pluck('ten')
            ->all();
    }

    public function test_truy_van_khong_dau_tim_thay_san_pham_co_dau(): void
    {
        // The MySQL collation folds tone marks but not "đ", so this returned nothing.
        $this->assertSame($this->names('đau đầu'), $this->names('dau dau'));
        $this->assertNotEmpty($this->names('dau dau'));
    }

    public function test_moi_tu_deu_phai_khop(): void
    {
        $results = $this->names('ho tre');

        // ORing the words would also return the paracetamol products, whose usage
        // text mentions neither.
        $this->assertSame(['Siro ho Prospan cho trẻ'], $results);
    }

    public function test_truy_van_khong_khop_gi_tra_rong(): void
    {
        $this->assertSame([], $this->names('khang sinh amoxicillin'));
    }

    public function test_san_pham_co_tu_khoa_trong_ten_xep_tren_san_pham_chi_neu_tu_khoa(): void
    {
        $results = $this->names('paracetamol');

        // Panadol contains paracetamol but does not say so in its name.
        $this->assertSame('Viên nén Paracetamol Stada 500mg', $results[0]);
        $this->assertSame('Viên nén Panadol Extra đỏ GSK', end($results));
    }

    public function test_ten_bat_dau_bang_tu_khoa_xep_dau(): void
    {
        $this->make('Thuốc ho bổ phế Panadol phối hợp', 'Panadol', 'Ho.');

        $this->assertSame('Viên nén Panadol Extra đỏ GSK', $this->names('panadol')[0]);
    }

    public function test_tim_thanh_phan_thay_san_pham_khong_neu_thanh_phan_trong_ten(): void
    {
        $this->assertContains('Viên nén Panadol Extra đỏ GSK', $this->names('caffeine'));
    }

    public function test_chi_van_ban_cach_dung_khong_du_khop(): void
    {
        // usage averages 1,400 characters on crawled rows; searching it turns almost
        // any symptom word into a catalog-wide match.
        $this->assertNotContains('Kem dưỡng ẩm Ceradan', $this->names('da kho'));
    }

    public function test_ky_tu_dai_dien_trong_truy_van_cua_hang_la_dau_tach_tu(): void
    {
        // Folding strips punctuation, so "%" cannot reach the LIKE — it splits the
        // query instead. "pa%ol" is therefore the two words "pa" and "ol".
        $this->assertSame(SanPham::searchWords('pa%ol'), ['pa', 'ol']);
        $this->assertSame($this->names('pa ol'), $this->names('pa%ol'));
    }

    public function test_ky_tu_dai_dien_giu_nguyen_nghia_den_trong_tim_kiem_quan_tri(): void
    {
        // The admin scope matches raw, unfolded text, so it is the one that needs
        // the wildcards escaped.
        $this->assertSame(0, SanPham::search('Paracetamo%')->count());
        $this->assertSame(1, SanPham::search('Paracetamol')->count());
    }

    public function test_ky_tu_dai_dien_don_duoc_xem_la_truy_van_rong(): void
    {
        // "%" folds away to nothing, which means "no filter" — the same as an
        // empty box, not a filter that silently matches everything.
        $this->assertCount(5, $this->names('%'));
    }

    public function test_truy_van_trong_giu_danh_muc_khong_loc(): void
    {
        $this->assertCount(5, $this->names(''));
        $this->assertCount(5, $this->names('   '));
    }

    public function test_trang_ket_qua_va_man_hinh_danh_muc_thong_nhat(): void
    {
        $fromSearch = $this->get(route('san-pham.tim-kiem', ['q' => 'paracetamol']))
            ->assertOk()
            ->viewData('sanPhams')
            ->pluck('ten')
            ->all();

        $fromCatalog = $this->get(route('san-pham.index', ['q' => 'paracetamol']))
            ->assertOk()
            ->viewData('sanPhams')
            ->pluck('ten')
            ->all();

        $this->assertSame($fromSearch, $fromCatalog);
        $this->assertSame($this->names('paracetamol'), $fromSearch);
    }

    public function test_tu_khoa_tim_kiem_duoc_giu_trong_lien_ket_phan_trang(): void
    {
        // Đủ hơn một trang mới có link phân trang để mà kiểm: catalog trong setUp chỉ có
        // 2 sản phẩm khớp "paracetamol", dưới PER_PAGE = 10, nên trang không hề render
        // link nào và một assertSee('paracetamol') trần chỉ khớp giá trị ô tìm kiếm.
        for ($i = 0; $i < 10; $i++) {
            $this->make("Viên nén Paracetamol phụ {$i}", 'Paracetamol 500mg', 'Giảm đau.');
        }

        // Từ khoá phải nằm trong chính href của trang 2, không chỉ đâu đó trên trang:
        // 'q=paracetamol' còn xuất hiện ở ô tìm kiếm và link "xem tất cả".
        $html = $this->get(route('san-pham.tim-kiem', ['q' => 'paracetamol']))
            ->assertOk()
            ->getContent();

        preg_match_all('#href="([^"]*page=2[^"]*)"#', $html, $matches);

        $this->assertNotEmpty($matches[1], 'Trang kết quả phải có link phân trang.');

        foreach (array_unique($matches[1]) as $href) {
            $this->assertStringContainsString('q=paracetamol', html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
        }
    }

    public function test_o_tim_kiem_dau_trang_giu_tu_khoa_hien_tai(): void
    {
        // The box used to clear itself on the results page, so refining a search
        // meant retyping it.
        $this->get(route('san-pham.tim-kiem', ['q' => 'paracetamol']))
            ->assertOk()
            ->assertSee('id="header-search" name="q" value="paracetamol"', false);
    }

    public function test_ket_qua_rong_neu_tu_khoa_va_co_duong_quay_lai(): void
    {
        $this->get(route('san-pham.tim-kiem', ['q' => 'khong-co-thuoc-nay']))
            ->assertOk()
            ->assertSee('khong-co-thuoc-nay')
            ->assertSee(route('san-pham.index'), false);
    }

    public function test_moi_trang_chi_co_mot_o_tim_kiem_va_hoat_dong_khong_can_javascript(): void
    {
        // Ô tìm kiếm của header là ô duy nhất trong app. Trước đây có ba ô cùng post
        // một `q` — header, trang chủ, trang catalog — và cần JS ẩn bớt để khỏi hiện
        // hai ô một lúc; đếm ở đây để không lặng lẽ mọc lại ô thứ hai.
        $action = 'action="' . route('san-pham.tim-kiem') . '" method="GET"';

        foreach ([route('trang-chu'), route('san-pham.index'), route('san-pham.tim-kiem', ['q' => 'paracetamol'])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, $action), "Trang {$url} phải có đúng một form tìm kiếm.");
            $this->assertStringContainsString('id="header-search" name="q"', $html);
            $this->assertStringNotContainsString('data-page-search', $html);
        }
    }

    public function test_o_tim_kiem_dau_trang_nam_ngoai_menu_thu_gon(): void
    {
        // Trên điện thoại ô tìm kiếm phải nằm ngay hàng header, không nằm trong burger:
        // #navbarNav là .collapse nên nội dung bên trong bị ẩn tới khi bấm nút.
        $html = $this->get(route('trang-chu'))->assertOk()->getContent();

        $box = strpos($html, 'id="header-search"');
        $collapse = strpos($html, 'id="navbarNav"');

        $this->assertNotFalse($box);
        $this->assertNotFalse($collapse);
        $this->assertLessThan($collapse, $box, 'Ô tìm kiếm phải đứng trước #navbarNav.');
    }
}
