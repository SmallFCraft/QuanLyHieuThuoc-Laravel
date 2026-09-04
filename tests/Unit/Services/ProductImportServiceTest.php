<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\ProductImportService;
use App\Support\LongChauProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): LongChauProduct
    {
        return LongChauProduct::fromNextData(['props' => ['pageProps' => ['product' => array_merge([
            'sku' => '00005713',
            'webName' => 'Viên nén Panadol Extra đỏ GSK (15 vỉ x 12 viên)',
            'prices' => [['level' => 1, 'price' => 252000]],
            'categories' => [
                ['level' => 1, 'name' => 'Thuốc'],
                ['level' => 2, 'name' => 'Thuốc giảm đau, hạ sốt, kháng viêm'],
            ],
            'ingredient' => [
                ['name' => 'Caffeine', 'shortDescription' => '65mg'],
                ['name' => 'Paracetamol', 'shortDescription' => '500mg'],
            ],
            'usage' => '<h3>Chỉ định</h3><p>Giảm đau, hạ sốt.</p>',
            'primaryImage' => ['url' => 'https://cdn.nhathuoclongchau.com.vn/v1/static/panadol.png'],
            'expirationDate' => '36 tháng',
            'prescription' => false,
        ], $overrides)]]]);
    }

    private function importer(): ProductImportService
    {
        return app(ProductImportService::class);
    }

    public function test_it_stores_the_catalog_fields_and_a_placeholder_batch(): void
    {
        $this->importer()->import($this->product());

        $product = Product::sole();

        $this->assertSame('Viên nén Panadol Extra đỏ GSK (15 vỉ x 12 viên)', $product->name);
        $this->assertSame(252000, $product->price_vnd);
        $this->assertSame('Caffeine 65mg, Paracetamol 500mg', $product->ingredients);
        // Stored as the CDN's resized variant, not the multi-megabyte master.
        $this->assertSame(
            'https://cdn.nhathuoclongchau.com.vn/unsafe/600x0/filters:quality(90):format(webp)/panadol.png',
            $product->image
        );
        $this->assertSame('LC-00005713', $product->batch_number);
        $this->assertSame(
            'Thuốc giảm đau, hạ sốt, kháng viêm',
            $product->category->name
        );

        // ProductObserver turns batch_number + expiration_date into the batch row
        // that FEFO and the expiry warnings read.
        $batch = ProductBatch::sole();
        $this->assertSame($product->MaSP, $batch->MaSP);
        $this->assertSame('LC-00005713', $batch->batch_number);
        $this->assertSame(
            $product->expiration_date->toDateString(),
            $batch->expiration_date->toDateString()
        );
    }

    public function test_the_expiry_date_reflects_the_published_shelf_life(): void
    {
        $this->travelTo('2026-08-30');

        $this->importer()->import($this->product(['expirationDate' => '24 tháng']));

        $this->assertSame('2028-08-30', Product::sole()->expiration_date->toDateString());
    }

    public function test_the_product_code_is_generated_from_the_category(): void
    {
        $this->importer()->import($this->product());

        // Product::booted() derives it from the category code; the crawler must not
        // set it by hand.
        $this->assertNotNull(Product::sole()->product_code);
    }

    public function test_a_second_crawl_updates_in_place_instead_of_duplicating(): void
    {
        $this->importer()->import($this->product());
        $this->importer()->import($this->product(['prices' => [['level' => 1, 'price' => 268000]]]));

        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductBatch::count());
        $this->assertSame(268000, Product::sole()->price_vnd);
    }

    public function test_a_recrawl_never_overwrites_stock_entered_by_staff(): void
    {
        $this->importer()->import($this->product());

        $product = Product::sole();
        $batch = ProductBatch::sole();

        // What a pharmacist would enter after real stock arrives.
        $batch->update(['stock_quantity' => 42, 'expiration_date' => '2027-01-15']);
        $product->forceFill([
            'stock_quantity' => 42,
            'reorder_point' => 7,
            'expiration_date' => '2027-01-15',
        ])->save();

        $this->importer()->import($this->product(['prices' => [['level' => 1, 'price' => 300000]]]));

        $batch->refresh();
        $product->refresh();

        $this->assertSame(42, $batch->stock_quantity, 'Real stock survives a recrawl.');
        $this->assertSame('2027-01-15', $batch->expiration_date->toDateString());
        $this->assertSame(7, $product->reorder_point);

        // products.stock_quantity là cột gác "Còn hàng"/"Hết hàng" và mức max của giỏ,
        // products.expiration_date là cột gác notExpired() — cả hai đều phải sống sót,
        // không chỉ dòng batch.
        $this->assertSame(42, $product->stock_quantity, 'Storefront stock survives a recrawl.');
        $this->assertSame('2027-01-15', $product->expiration_date->toDateString());

        $this->assertSame(300000, $product->price_vnd, 'Catalog fields still refresh.');
    }

    public function test_categories_are_reused_across_products(): void
    {
        $this->importer()->import($this->product());
        $this->importer()->import($this->product([
            'sku' => '00009999',
            'webName' => 'Thuốc Efferalgan 500mg',
        ]));

        $this->assertSame(1, Category::count());
        $this->assertSame(2, Product::count());
    }

    public function test_an_existing_category_is_matched_by_name_not_duplicated(): void
    {
        Category::create(['name' => 'Thuốc giảm đau, hạ sốt, kháng viêm']);

        $this->importer()->import($this->product());

        $this->assertSame(1, Category::count());
    }

    /**
     * Long Châu publishes the same level-2 breadcrumb under more than one spelling
     * ("Cơ - xương - khớp" / "Cơ xương khớp"), which split 114 products across two
     * storefront entries a customer reads as one.
     */
    public function test_category_spellings_that_differ_only_by_punctuation_resolve_to_one_row(): void
    {
        $spelt = fn (string $name, string $sku) => $this->product([
            'sku' => $sku,
            'categories' => [['level' => 1, 'name' => 'Thuốc'], ['level' => 2, 'name' => $name]],
        ]);

        $this->importer()->import($spelt('Cơ - xương - khớp', '00000001'));
        $this->importer()->import($spelt('Cơ xương khớp', '00000002'));
        $this->importer()->import($spelt('CƠ XƯƠNG KHỚP', '00000003'));

        $this->assertSame(1, Category::count());
        // First spelling seen keeps the display name.
        $this->assertSame('Cơ - xương - khớp', Category::sole()->name);
        $this->assertSame(3, Product::where('MaDM', Category::sole()->MaDM)->count());
    }

    /** The per-run cache must not hand back a different row than the database would. */
    public function test_a_category_created_before_the_crawl_is_matched_across_spellings(): void
    {
        Category::create(['name' => 'Cơ xương khớp']);

        $this->importer()->import($this->product([
            'categories' => [['level' => 1, 'name' => 'Thuốc'], ['level' => 2, 'name' => 'Cơ - xương - khớp']],
        ]));

        $this->assertSame(1, Category::count());
        $this->assertSame('Cơ xương khớp', Product::sole()->category->name);
    }

    public function test_a_product_that_changed_category_is_moved(): void
    {
        $this->importer()->import($this->product());
        $this->importer()->import($this->product([
            'categories' => [
                ['level' => 1, 'name' => 'Thuốc'],
                ['level' => 2, 'name' => 'Thuốc tiêu hoá & gan mật'],
            ],
        ]));

        $this->assertSame(2, Category::count());
        $this->assertSame('Thuốc tiêu hoá & gan mật', Product::sole()->category->name);
    }

    public function test_generated_stock_stays_within_the_configured_range(): void
    {
        foreach (range(1, 15) as $i) {
            $this->importer()->import($this->product([
                'sku' => '0000' . $i,
                'webName' => "Thuốc số {$i}",
            ]));
        }

        foreach (Product::all() as $product) {
            $this->assertGreaterThanOrEqual(0, $product->stock_quantity);
            $this->assertLessThanOrEqual(200, $product->stock_quantity);
            $this->assertGreaterThanOrEqual(10, $product->reorder_point);
            $this->assertLessThanOrEqual(30, $product->reorder_point);
        }
    }

    public function test_a_soft_deleted_product_is_restored_rather_than_duplicated(): void
    {
        $this->importer()->import($this->product());
        Product::sole()->delete();

        $this->importer()->import($this->product());

        $this->assertSame(1, Product::withTrashed()->count());
        $this->assertNull(Product::sole()->deleted_at);
    }
}
