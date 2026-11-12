<?php

namespace Tests\Unit\Services;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\LoSanPham;
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

        $sanPham = SanPham::sole();

        $this->assertSame('Viên nén Panadol Extra đỏ GSK (15 vỉ x 12 viên)', $sanPham->name);
        $this->assertSame(252000, $sanPham->price_vnd);
        $this->assertSame('Caffeine 65mg, Paracetamol 500mg', $sanPham->ingredients);
        // Stored as the CDN's resized variant, not the multi-megabyte master.
        $this->assertSame(
            'https://cdn.nhathuoclongchau.com.vn/unsafe/600x0/filters:quality(90):format(webp)/panadol.png',
            $sanPham->image
        );
        $this->assertSame('LC-00005713', $sanPham->batch_number);
        $this->assertSame(
            'Thuốc giảm đau, hạ sốt, kháng viêm',
            $sanPham->danhMuc->name
        );

        // ProductObserver turns batch_number + expiration_date into the batch row
        // that FEFO and the expiry warnings read.
        $lo = LoSanPham::sole();
        $this->assertSame($sanPham->MaSP, $lo->MaSP);
        $this->assertSame('LC-00005713', $lo->batch_number);
        $this->assertSame(
            $sanPham->expiration_date->toDateString(),
            $lo->expiration_date->toDateString()
        );
    }

    public function test_the_expiry_date_reflects_the_published_shelf_life(): void
    {
        $this->travelTo('2026-08-30');

        $this->importer()->import($this->product(['expirationDate' => '24 tháng']));

        $this->assertSame('2028-08-30', SanPham::sole()->expiration_date->toDateString());
    }

    public function test_the_product_code_is_generated_from_the_category(): void
    {
        $this->importer()->import($this->product());

        // SanPham::booted() derives it from the category code; the crawler must not
        // set it by hand.
        $this->assertNotNull(SanPham::sole()->product_code);
    }

    public function test_a_second_crawl_updates_in_place_instead_of_duplicating(): void
    {
        $this->importer()->import($this->product());
        $this->importer()->import($this->product(['prices' => [['level' => 1, 'price' => 268000]]]));

        $this->assertSame(1, SanPham::count());
        $this->assertSame(1, LoSanPham::count());
        $this->assertSame(268000, SanPham::sole()->price_vnd);
    }

    public function test_a_recrawl_never_overwrites_stock_entered_by_staff(): void
    {
        $this->importer()->import($this->product());

        $sanPham = SanPham::sole();
        $lo = LoSanPham::sole();

        // What a pharmacist would enter after real stock arrives.
        $lo->update(['stock_quantity' => 42, 'expiration_date' => '2027-01-15']);
        $sanPham->forceFill([
            'stock_quantity' => 42,
            'reorder_point' => 7,
            'expiration_date' => '2027-01-15',
        ])->save();

        $this->importer()->import($this->product(['prices' => [['level' => 1, 'price' => 300000]]]));

        $lo->refresh();
        $sanPham->refresh();

        $this->assertSame(42, $lo->stock_quantity, 'Real stock survives a recrawl.');
        $this->assertSame('2027-01-15', $lo->expiration_date->toDateString());
        $this->assertSame(7, $sanPham->reorder_point);

        // products.stock_quantity là cột gác "Còn hàng"/"Hết hàng" và mức max của giỏ,
        // products.expiration_date là cột gác notExpired() — cả hai đều phải sống sót,
        // không chỉ dòng batch.
        $this->assertSame(42, $sanPham->stock_quantity, 'Storefront stock survives a recrawl.');
        $this->assertSame('2027-01-15', $sanPham->expiration_date->toDateString());

        $this->assertSame(300000, $sanPham->price_vnd, 'Catalog fields still refresh.');
    }

    public function test_categories_are_reused_across_products(): void
    {
        $this->importer()->import($this->product());
        $this->importer()->import($this->product([
            'sku' => '00009999',
            'webName' => 'Thuốc Efferalgan 500mg',
        ]));

        $this->assertSame(1, DanhMuc::count());
        $this->assertSame(2, SanPham::count());
    }

    public function test_an_existing_category_is_matched_by_name_not_duplicated(): void
    {
        DanhMuc::create(['name' => 'Thuốc giảm đau, hạ sốt, kháng viêm']);

        $this->importer()->import($this->product());

        $this->assertSame(1, DanhMuc::count());
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

        $this->assertSame(1, DanhMuc::count());
        // First spelling seen keeps the display name.
        $this->assertSame('Cơ - xương - khớp', DanhMuc::sole()->name);
        $this->assertSame(3, SanPham::where('MaDM', DanhMuc::sole()->MaDM)->count());
    }

    /** The per-run cache must not hand back a different row than the database would. */
    public function test_a_category_created_before_the_crawl_is_matched_across_spellings(): void
    {
        DanhMuc::create(['name' => 'Cơ xương khớp']);

        $this->importer()->import($this->product([
            'categories' => [['level' => 1, 'name' => 'Thuốc'], ['level' => 2, 'name' => 'Cơ - xương - khớp']],
        ]));

        $this->assertSame(1, DanhMuc::count());
        $this->assertSame('Cơ xương khớp', SanPham::sole()->danhMuc->name);
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

        $this->assertSame(2, DanhMuc::count());
        $this->assertSame('Thuốc tiêu hoá & gan mật', SanPham::sole()->danhMuc->name);
    }

    public function test_generated_stock_stays_within_the_configured_range(): void
    {
        foreach (range(1, 15) as $i) {
            $this->importer()->import($this->product([
                'sku' => '0000' . $i,
                'webName' => "Thuốc số {$i}",
            ]));
        }

        foreach (SanPham::all() as $sanPham) {
            $this->assertGreaterThanOrEqual(0, $sanPham->stock_quantity);
            $this->assertLessThanOrEqual(200, $sanPham->stock_quantity);
            $this->assertGreaterThanOrEqual(10, $sanPham->reorder_point);
            $this->assertLessThanOrEqual(30, $sanPham->reorder_point);
        }
    }

    public function test_a_soft_deleted_product_is_restored_rather_than_duplicated(): void
    {
        $this->importer()->import($this->product());
        SanPham::sole()->delete();

        $this->importer()->import($this->product());

        $this->assertSame(1, SanPham::withTrashed()->count());
        $this->assertNull(SanPham::sole()->deleted_at);
    }
}
