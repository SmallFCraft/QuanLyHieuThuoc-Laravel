<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hero's stat strip used to hard-code "10k+ sản phẩm" and "24/7 tư vấn" while the
 * catalog held 3,132 sellable rows and the pharmacy answered 8:00-20:00. Both numbers
 * now come from the same filter the catalog sells by, so the claim cannot drift.
 */
class HomeHeroStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hero_counts_only_what_the_shop_can_actually_sell(): void
    {
        $painRelief = Category::create(['name' => 'Thuốc giảm đau']);
        $skincare = Category::create(['name' => 'Chăm sóc da']);
        $empty = Category::create(['name' => 'Danh mục rỗng']);

        $this->product($painRelief, 'Paracetamol Stada 500mg');
        $this->product($painRelief, 'Panadol Extra đỏ GSK');
        $this->product($skincare, 'Kem dưỡng ẩm Ceradan');
        $this->product($skincare, 'Sữa rửa mặt hết hàng', ['stock_quantity' => 0]);
        $this->product($empty, 'Siro ho đã hết hạn', ['expiration_date' => now()->subDay()]);

        $html = $this->get(route('home'))->assertOk()->getContent();

        // 3 bán được, không tính hàng hết và hàng quá hạn. 2 danh mục còn hàng, không
        // tính danh mục chỉ còn hàng quá hạn.
        $this->assertStringContainsString('<span class="home-stat-value">3</span>', $html);
        $this->assertStringContainsString('<span class="home-stat-value">2</span>', $html);

        // Số cũ ghi cứng phải biến mất hẳn.
        $this->assertStringNotContainsString('10k+', $html);
    }

    private function product(Category $category, string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'MaDM' => $category->MaDM,
            'name' => $name,
            'price' => 45000,
            'shelf_life_months' => 36,
            'expiration_date' => now()->addMonths(36),
            'stock_quantity' => 40,
            'reorder_point' => 10,
            'batch_number' => 'LC-' . fake()->unique()->numerify('########'),
        ], $overrides));
    }
}
