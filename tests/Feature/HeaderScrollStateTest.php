<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trạng thái cuộn của header: logo nhường chỗ ngang cho ô tìm kiếm khi khách cuộn
 * xuống. Phần chuyển động là CSS + IntersectionObserver nên không test được ở đây;
 * cái test được là những mảnh server phải ship để cơ chế đó chạy.
 */
class HeaderScrollStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sentinel_ships_on_every_storefront_page(): void
    {
        // Không có nó thì header-scroll.js thoát sớm và header đứng ở trạng thái đầy đủ.
        foreach ([route('home'), route('products.index')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('data-header-sentinel', false);
        }
    }

    public function test_the_header_styles_come_from_a_class_not_an_inline_style(): void
    {
        // Inline style thắng stylesheet, nên trạng thái cuộn không thể override được
        // nếu nav quay về mang style="background: ...".
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('class="navbar navbar-expand-lg fixed-top site-header"', $html);
        $this->assertStringContainsString(':root[data-header-scrolled] .site-header', $html);
        $this->assertStringContainsString(':root[data-header-scrolled] .navbar-brand', $html);
    }

    public function test_both_placeholder_sets_reach_the_browser(): void
    {
        // Ô hẹp lại ~90px khi header đầy đủ, nên hai bộ chuỗi là hai độ dài khác nhau.
        // Thiếu bộ rộng thì JS lặng lẽ dùng bộ hẹp và mất hiệu ứng.
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
        $encoded = fn (string $value) => trim(json_encode($value, $flags), '"');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('placeholdersWide', false)
            ->assertSee($encoded(config('search.placeholder_rotation')[0]), false)
            ->assertSee($encoded(config('search.placeholder_rotation_wide')[0]), false);
    }

    public function test_the_narrow_placeholders_stay_shorter_than_the_wide_ones(): void
    {
        // Ngưỡng thật là chiều rộng pixel, đo trong browser. Ở đây chỉ chốt thứ tự để
        // hai bộ không bị đổi chỗ cho nhau khi ai đó sửa config.
        $narrow = max(array_map('mb_strlen', config('search.placeholder_rotation')));
        $wide = max(array_map('mb_strlen', config('search.placeholder_rotation_wide')));

        $this->assertLessThan($wide, $narrow);
        // 122px lòng trong ở 375px chứa được khoảng 15 ký tự chữ này.
        $this->assertLessThanOrEqual(15, $narrow);
    }

    public function test_the_search_box_stays_outside_the_collapsed_menu(): void
    {
        // Cả cơ chế này dựa vào việc ô tìm kiếm nằm cùng hàng với logo. Rơi vào trong
        // #navbarNav là nó bị collapse ẩn trên điện thoại và không còn gì để nở ra.
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'id="navbarNav"'),
            strpos($html, 'id="header-search"'),
        );
    }
}
