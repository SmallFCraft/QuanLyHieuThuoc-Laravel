<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\TruyVanTimKiem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Panel tìm kiếm được nhúng vào trang thay vì gọi thêm một endpoint, nên nội dung của
 * nó là HTML server render và test được ở đây. Lịch sử tìm kiếm nằm ở localStorage
 * nên không thuộc phạm vi test này.
 */
class BangTimKiemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau, hạ sốt']);

        foreach (range(1, 3) as $i) {
            SanPham::create(['ma_dm' => $danhMuc->ma_dm,
                'ten' => "Viên nén Paracetamol số {$i}",
                'gia' => 50000 * $i,
                'ngay_het_han' => now()->addYears(2),
                'so_luong_ton' => 20,
                'muc_canh_bao' => 5,
                'so_lo' => "LC-panel-{$i}",
                'luot_xem' => 100 - $i,
            ]);
        }
    }

    public function test_template_bang_tim_kiem_co_tren_moi_trang_cua_hang(): void
    {
        // <template>, không phải <div>: nội dung là nguồn để JS clone, không được
        // render thẳng vào layout.
        foreach ([route('trang-chu'), route('san-pham.index')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('<template id="search-panel-data">', false);
        }
    }

    public function test_bang_tim_kiem_co_tu_khoa_xu_huong_danh_muc_hang_dau_va_san_pham_pho_bien(): void
    {
        TruyVanTimKiem::updateOrCreate(
            ['term_folded' => 'thuoc ho tre em'],
            ['term' => 'thuốc ho trẻ em', 'hits' => 12, 'last_searched_at' => now()],
        );
        Cache::forget(TruyVanTimKiem::CACHE_KEY);

        $response = $this->get(route('trang-chu'))->assertOk();

        $response->assertSee(__('messages.search_trending'))
            ->assertSee('thuốc ho trẻ em')
            ->assertSee(__('messages.search_top_categories'))
            ->assertSee(__('messages.search_most_viewed'));

        // Chỉ tính phần trong <template>: trang chủ tự render tên sản phẩm ở hai lưới
        // "mới ra mắt" và "nổi bật", nên quét cả trang thì xoá hẳn khối panel vẫn xanh.
        $panel = $this->panelMarkup($response->getContent());

        // Xem nhiều nhất là số 1 (luot_xem 99).
        $this->assertStringContainsString('Viên nén Paracetamol số 1', $panel);
    }

    /** Phần HTML từ thẻ <template> của panel trở đi. */
    private function panelMarkup(string $html): string
    {
        $start = strpos($html, '<template id="search-panel-data">');

        $this->assertNotFalse($start, 'Panel template không có trên trang.');

        return substr($html, $start);
    }

    public function test_bang_tim_kiem_hien_toi_da_ba_san_pham_pho_bien(): void
    {
        foreach (range(4, 8) as $i) {
            SanPham::create(['ma_dm' => DanhMuc::sole()->ma_dm,
                'ten' => "Viên nén Paracetamol số {$i}",
                'gia' => 10000,
                'ngay_het_han' => now()->addYears(2),
                'so_luong_ton' => 5,
                'muc_canh_bao' => 2,
                'so_lo' => "LC-panel-{$i}",
                'luot_xem' => 10,
            ]);
        }

        $panel = $this->panelMarkup($this->get(route('trang-chu'))->assertOk()->getContent());

        $this->assertSame(3, substr_count($panel, 'class="suggestion-item product-item"'));
    }

    public function test_san_pham_het_hang_khong_duoc_de_xuat_trong_bang_tim_kiem(): void
    {
        // Panel là một lối vào bán hàng: dẫn vào một sản phẩm hết hàng là ngõ cụt.
        // Không Cache::flush() ở đây — cache phải được chính lượt save() dọn, nếu không
        // thì test chỉ chứng minh query đúng chứ không chứng minh cache hết hạn đúng.
        $this->get(route('trang-chu'))->assertOk();

        SanPham::each(fn (SanPham $sanPham) => $sanPham->forceFill(['so_luong_ton' => 0])->save());

        $panel = $this->panelMarkup($this->get(route('trang-chu'))->assertOk()->getContent());

        $this->assertStringNotContainsString('class="suggestion-item product-item"', $panel);
        $this->assertStringNotContainsString(__('messages.search_most_viewed'), $panel);
    }

    public function test_nhan_xu_huong_la_lien_ket_that_de_hoat_dong_khong_can_javascript(): void
    {
        TruyVanTimKiem::updateOrCreate(
            ['term_folded' => 'collagen'],
            ['term' => 'collagen', 'hits' => 20, 'last_searched_at' => now()],
        );
        Cache::forget(TruyVanTimKiem::CACHE_KEY);

        $this->get(route('trang-chu'))
            ->assertOk()
            ->assertSee(route('san-pham.tim-kiem', ['q' => 'collagen']), false);
    }

    public function test_chu_ngon_ngu_cua_bang_tim_kiem_den_duoc_trinh_duyet(): void
    {
        // JS đọc chuỗi từ window.PharmacySearchLang; thiếu là panel hiện chuỗi dự phòng
        // tiếng Việt cứng trong JS, tức là hai nguồn chữ.
        //
        // So trên dạng đã json_encode: @json thoát tiếng Việt thành \uXXXX và dấu " thành
        // " (JSON_HEX_QUOT), nên so với chuỗi thô sẽ không khớp dù chữ đã có mặt.
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        $encoded = fn (string $value) => trim(json_encode($value, $flags), '"');

        $this->get(route('trang-chu'))
            ->assertOk()
            ->assertSee('window.PharmacySearchLang', false)
            ->assertSee($encoded(__('messages.search_history_clear')), false)
            ->assertSee($encoded(__('messages.search_view_all')), false)
            ->assertSee($encoded(config('search.placeholder_rotation')[0]), false);
    }

    public function test_hop_dong_endpoint_goi_y_khong_doi(): void
    {
        // Panel dùng chung container với khối gợi ý; hợp đồng JSON không được đổi.
        $this->getJson(route('san-pham.goi-y', ['q' => 'paracetamol']))
            ->assertOk()
            ->assertJsonStructure([
                'products' => [['ma_sp', 'name', 'category', 'price', 'image_url', 'url']],
                'categories',
            ]);
    }
}
