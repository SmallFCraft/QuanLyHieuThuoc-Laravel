<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trạng thái cuộn của header: logo nhường chỗ ngang cho ô tìm kiếm khi khách cuộn
 * xuống. Phần chuyển động là CSS + IntersectionObserver nên không test được ở đây;
 * cái test được là những mảnh server phải ship để cơ chế đó chạy.
 */
class TrangThaiCuonDauTrangTest extends TestCase
{
    use RefreshDatabase;

    public function test_moc_cuon_duoc_gui_tren_moi_trang_cua_hang(): void
    {
        // Không có nó thì header-scroll.js thoát sớm và header đứng ở trạng thái đầy đủ.
        foreach ([route('trang-chu'), route('san-pham.index')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('data-header-sentinel', false);
        }
    }

    public function test_kieu_dau_trang_den_tu_class_khong_phai_style_inline(): void
    {
        // Inline style thắng stylesheet, nên trạng thái cuộn không thể override được
        // nếu nav quay về mang style="background: ...".
        $html = $this->get(route('trang-chu'))->assertOk()->getContent();

        $this->assertStringContainsString('class="navbar navbar-expand-lg fixed-top site-header"', $html);
        $this->assertStringContainsString(':root[data-header-scrolled] .site-header', $html);
        $this->assertStringContainsString(':root[data-header-scrolled] .navbar-brand', $html);
    }

    public function test_ca_hai_bo_chu_goi_y_den_duoc_trinh_duyet(): void
    {
        // Ô hẹp lại ~90px khi header đầy đủ, nên hai bộ chuỗi là hai độ dài khác nhau.
        // Thiếu bộ rộng thì JS lặng lẽ dùng bộ hẹp và mất hiệu ứng.
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        $encoded = fn (string $value) => trim(json_encode($value, $flags), '"');

        $this->get(route('trang-chu'))
            ->assertOk()
            ->assertSee('placeholdersWide', false)
            ->assertSee($encoded(config('search.placeholder_rotation')[0]), false)
            ->assertSee($encoded(config('search.placeholder_rotation_wide')[0]), false);
    }

    public function test_chu_goi_y_hep_ngan_hon_chu_goi_y_rong(): void
    {
        // Ngưỡng thật là chiều rộng pixel, đo trong browser. Ở đây chỉ chốt thứ tự để
        // hai bộ không bị đổi chỗ cho nhau khi ai đó sửa config.
        $narrow = max(array_map('mb_strlen', config('search.placeholder_rotation')));
        $wide = max(array_map('mb_strlen', config('search.placeholder_rotation_wide')));

        $this->assertLessThan($wide, $narrow);
        // 122px lòng trong ở 375px chứa được khoảng 15 ký tự chữ này.
        $this->assertLessThanOrEqual(15, $narrow);
    }

    public function test_o_tim_kiem_nam_ngoai_menu_thu_gon(): void
    {
        // Cả cơ chế này dựa vào việc ô tìm kiếm nằm cùng hàng với logo. Rơi vào trong
        // #navbarNav là nó bị collapse ẩn trên điện thoại và không còn gì để nở ra.
        $html = $this->get(route('trang-chu'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'id="navbarNav"'),
            strpos($html, 'id="header-search"'),
        );
    }

    public function test_kich_thuoc_anh_og_khop_tai_nguyen_duoc_mo_ta(): void
    {
        // Literal (2816, 1536) đã sai lần đầu logo bị nén còn 512x512: scraper đọc
        // đúng con số sai và cắt tỉa card sai tỷ lệ. Chốt con số thật lấy từ file.
        $html = $this->get(route('trang-chu'))->assertOk()->getContent();

        $size = getimagesize(public_path('images/logo.png'));
        $this->assertNotFalse($size, 'assets/images/logo.png phải tồn tại');
        $this->assertStringContainsString(
            'property="og:image:width" content="' . $size[0] . '"',
            $html,
        );
        $this->assertStringContainsString(
            'property="og:image:height" content="' . $size[1] . '"',
            $html,
        );
    }

    public function test_lien_ket_thuong_hieu_luon_co_ten_tro_nang_doc_duoc(): void
    {
        // Dưới lg chữ tên brand bị d-none, ảnh thì alt="": nếu link không tự
        // aria-label thì trình đọc màn hình chỉ nghe một <a> trống trên mobile.
        $html = $this->get(route('trang-chu'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'class="navbar-brand d-flex align-items-center overflow-hidden flex-shrink-0" href="' . route('trang-chu') . '" aria-label="' . __('messages.app_name') . '"',
            $html,
        );
    }
}
