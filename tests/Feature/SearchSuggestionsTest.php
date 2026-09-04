<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The autocomplete endpoint fires on every keystroke past a 300ms debounce, so it
 * has to be both cheap and consistent with the results page the customer lands on.
 */
class SearchSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $painRelief = Category::create(['name' => 'Thuốc giảm đau, hạ sốt']);
        $skincare = Category::create(['name' => 'Chăm sóc da mặt']);

        $this->make($painRelief, 'Viên nén Paracetamol Stada 500mg', 'Paracetamol 500mg');
        $this->make($painRelief, 'Viên nén Panadol Extra đỏ GSK', 'Caffeine 65mg, Paracetamol 500mg');
        $this->make($skincare, 'Kem dưỡng ẩm Ceradan', 'Ceramide');
    }

    private function make(Category $category, string $name, string $ingredients): Product
    {
        return Product::create([
            'MaDM' => $category->MaDM,
            'name' => $name,
            'ingredients' => $ingredients,
            'price' => 50000,
            'expiration_date' => now()->addYears(2),
            'stock_quantity' => 30,
            'reorder_point' => 10,
            'batch_number' => 'LC-' . md5($name),
        ]);
    }

    private function suggest(string $term): array
    {
        return $this->getJson(route('products.suggestions', ['q' => $term]))
            ->assertOk()
            ->json();
    }

    public function test_it_suggests_matching_products_with_their_display_fields(): void
    {
        $data = $this->suggest('panadol');

        $this->assertCount(1, $data['products']);
        $this->assertSame('Viên nén Panadol Extra đỏ GSK', $data['products'][0]['name']);
        $this->assertSame('Thuốc giảm đau, hạ sốt', $data['products'][0]['category']);
        $this->assertArrayHasKey('price', $data['products'][0]);
        $this->assertArrayHasKey('url', $data['products'][0]);
    }

    public function test_an_accent_free_query_suggests_accented_products(): void
    {
        $data = $this->suggest('duong am');

        $this->assertSame('Kem dưỡng ẩm Ceradan', $data['products'][0]['name']);
    }

    public function test_suggestions_are_ranked_like_the_results_page(): void
    {
        $names = collect($this->suggest('paracetamol')['products'])->pluck('name')->all();

        $this->assertSame('Viên nén Paracetamol Stada 500mg', $names[0]);
    }

    public function test_a_single_character_query_is_refused(): void
    {
        // "đ" is two bytes, so the old strlen() gate let it through and matched most
        // of the catalog on one keystroke.
        foreach (['h', 'đ', 'ô'] as $term) {
            $data = $this->suggest($term);

            $this->assertSame([], $data['products'], "Query '{$term}' should be refused.");
            $this->assertSame([], $data['categories']);
        }
    }

    public function test_the_shape_is_stable_when_nothing_matches(): void
    {
        // The JS destructures products and categories; an empty top-level array
        // used to arrive instead.
        $data = $this->suggest('khong-ton-tai-gi-ca');

        $this->assertSame([], $data['products']);
        $this->assertSame([], $data['categories']);
    }

    public function test_matching_categories_are_suggested(): void
    {
        $data = $this->suggest('cham soc da');

        $this->assertSame('Chăm sóc da mặt', $data['categories'][0]['name']);
    }

    public function test_a_category_without_stock_is_not_suggested(): void
    {
        Category::create(['name' => 'Danh mục rỗng']);

        $data = $this->suggest('danh muc rong');

        $this->assertSame([], $data['categories']);
    }

    public function test_out_of_stock_products_are_not_suggested(): void
    {
        Product::where('name', 'like', 'Viên nén Panadol%')->update(['stock_quantity' => 0]);

        $this->assertSame([], $this->suggest('panadol')['products']);
    }

    public function test_it_answers_within_a_query_budget(): void
    {
        // One keystroke must not fan out into a query per category.
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $this->suggest('paracetamol');

        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $queries, "Suggestions ran {$queries} queries.");
    }
}
