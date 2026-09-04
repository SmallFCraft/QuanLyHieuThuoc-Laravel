<?php

namespace Tests\Unit\Support;

use App\Services\DrugSafetyService;
use App\Support\LongChauProduct;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LongChauProductTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/fixtures/longchau/{$name}"));
    }

    public function test_it_maps_every_product_field_from_a_real_page(): void
    {
        $product = LongChauProduct::fromHtml($this->fixture('panadol.html'));

        $this->assertNotNull($product);
        $this->assertSame('00005713', $product->sku);
        $this->assertSame(
            'Viên nén Panadol Extra đỏ GSK giảm mạnh các cơn đau, hạ sốt, điều trị đau đầu, đau lưng (15 vỉ x 12 viên)',
            $product->name
        );
        $this->assertSame(252000, $product->price);
        $this->assertSame('Thuốc giảm đau, hạ sốt, kháng viêm', $product->categoryName);
        $this->assertStringContainsString('DSC_099842_74c1fc532a.png', $product->imageUrl);
        $this->assertSame(36, $product->shelfLifeMonths);
        $this->assertFalse($product->prescription);
    }

    public function test_it_takes_the_box_price_not_the_per_unit_price(): void
    {
        // prices[] on the fixture is [Hộp 252000, Vỉ 16800, Viên 1400]. The row
        // describes a box, so the box price is the only one that matches it.
        $this->assertSame(252000, LongChauProduct::fromHtml($this->fixture('panadol.html'))->price);
    }

    public function test_a_product_without_a_published_price_is_rejected(): void
    {
        // Vietnamese law bars listing prescription drug prices online, so Long Châu
        // sends price: null for every unit. products.price is NOT NULL.
        $this->assertNull(LongChauProduct::fromHtml($this->fixture('bisoloc-no-price.html')));
    }

    public function test_missing_next_data_is_reported_separately_from_an_unusable_product(): void
    {
        $this->assertNull(LongChauProduct::extractNextData('<html><body>404</body></html>'));
        $this->assertIsArray(LongChauProduct::extractNextData($this->fixture('panadol.html')));
    }

    public function test_ingredients_use_the_format_drug_safety_service_parses(): void
    {
        $product = LongChauProduct::fromHtml($this->fixture('panadol.html'));

        $this->assertSame('Caffeine 65mg, Paracetamol 500mg', $product->ingredients);

        // The cart interaction check reads products.ingredients, so the crawler's
        // format has to survive its tokeniser or every warning goes silent.
        $extracted = app(DrugSafetyService::class)->extractIngredients($product->ingredients);

        $this->assertContains('paracetamol', $extracted);
        $this->assertContains('caffeine', $extracted);
    }

    public function test_usage_is_plain_text_carrying_both_indications_and_dosage(): void
    {
        $usage = LongChauProduct::fromHtml($this->fixture('panadol.html'))->usage;

        $this->assertStringNotContainsString('<', $usage);
        $this->assertStringNotContainsString('&nbsp;', $usage);
        $this->assertStringContainsString('Chỉ định', $usage);
        $this->assertStringContainsString('Liều dùng', $usage);
        $this->assertStringContainsString('Không dùng quá 8 viên trong 24 giờ', $usage);
    }

    public static function shelfLifeProvider(): array
    {
        return [
            'months' => ['36 tháng', 36],
            'zero padded years' => ['02 năm', 24],
            'years' => ['3 năm', 36],
            'unparseable' => ['Xem trên bao bì', null],
            'absent' => [null, null],
            'out of range' => ['360 tháng', null],
        ];
    }

    #[DataProvider('shelfLifeProvider')]
    public function test_it_reads_the_shelf_life_long_chau_publishes(?string $raw, ?int $expected): void
    {
        $product = LongChauProduct::fromNextData($this->nextData(['expirationDate' => $raw]));

        // Null, never a default: this figure is shown to customers as the
        // manufacturer's, so a guess must not be presented as one.
        $this->assertSame($expected, $product->shelfLifeMonths);
    }

    public function test_the_placeholder_batch_is_still_dated_without_a_published_shelf_life(): void
    {
        $product = LongChauProduct::fromNextData($this->nextData(['expirationDate' => null]));

        $this->assertNull($product->shelfLifeMonths);
        $this->assertSame(
            '2028-08-30',
            $product->expirationDate(Carbon::parse('2026-08-30'))->toDateString(),
            'products.expiration_date is NOT NULL, so the batch still needs a date.'
        );
    }

    public function test_the_batch_number_is_derived_from_the_sku_so_recrawling_reuses_the_batch(): void
    {
        $product = LongChauProduct::fromHtml($this->fixture('panadol.html'));

        // No date component on purpose: the importer matches rows on batch_number,
        // so a month-stamped value would insert a duplicate product every month.
        $this->assertSame('LC-00005713', $product->batchNumber());
    }

    public function test_the_expiry_date_follows_the_published_shelf_life(): void
    {
        $product = LongChauProduct::fromHtml($this->fixture('panadol.html'));

        $this->assertSame(
            '2029-08-30',
            $product->expirationDate(Carbon::parse('2026-08-30'))->toDateString()
        );
    }

    public function test_products_missing_a_name_category_or_sku_are_rejected(): void
    {
        $this->assertNull(LongChauProduct::fromNextData($this->nextData(['webName' => '', 'name' => ''])));
        $this->assertNull(LongChauProduct::fromNextData($this->nextData(['sku' => null])));
        $this->assertNull(LongChauProduct::fromNextData($this->nextData(['categories' => []])));
    }

    public function test_a_price_outside_the_storable_range_is_rejected(): void
    {
        $this->assertNull(LongChauProduct::fromNextData($this->nextData([
            'prices' => [['level' => 1, 'price' => 0]],
        ])));
        $this->assertNull(LongChauProduct::fromNextData($this->nextData([
            'prices' => [['level' => 1, 'price' => 100_000_000]],
        ])));
    }

    public function test_it_falls_back_to_a_smaller_pack_when_the_box_has_no_price(): void
    {
        $product = LongChauProduct::fromNextData($this->nextData([
            'prices' => [
                ['level' => 1, 'price' => null],
                ['level' => 3, 'price' => 1400],
                ['level' => 2, 'price' => 16800],
            ],
        ]));

        $this->assertSame(16800, $product->price, 'The largest pack with a price wins, not the cheapest.');
    }

    public function test_oversized_strings_from_the_source_cannot_break_the_insert(): void
    {
        $product = LongChauProduct::fromNextData($this->nextData([
            'webName' => str_repeat('a', 400),
            'primaryImage' => ['url' => 'https://cdn.nhathuoclongchau.com.vn/v1/static/' . str_repeat('b', 300) . '.png'],
        ]));

        $this->assertLessThanOrEqual(255, mb_strlen($product->name));
        $this->assertNull($product->imageUrl);
    }

    public function test_images_are_requested_through_the_cdn_resize_endpoint(): void
    {
        // The originals are 1.5-3.5 MB each. Long Châu's own resize endpoint serves
        // the same image as webp at 40-100 KB, which is what a product card needs.
        $product = LongChauProduct::fromHtml($this->fixture('panadol.html'));

        $this->assertSame(
            'https://cdn.nhathuoclongchau.com.vn/unsafe/600x0/filters:quality(90):format(webp)/DSC_099842_74c1fc532a.png',
            $product->imageUrl
        );
    }

    public function test_a_nested_image_path_keeps_its_subfolder(): void
    {
        // Only the v1/static/ prefix is dropped; the resize endpoint 500s if a
        // remaining subfolder such as smalls/ is stripped too.
        $product = LongChauProduct::fromNextData($this->nextData([
            'primaryImage' => ['url' => 'https://cdn.nhathuoclongchau.com.vn/v1/static/smalls/dt_paracetamol_kabi.jpg'],
        ]));

        $this->assertSame(
            'https://cdn.nhathuoclongchau.com.vn/unsafe/600x0/filters:quality(90):format(webp)/smalls/dt_paracetamol_kabi.jpg',
            $product->imageUrl
        );
    }

    public function test_an_unexpected_image_host_or_path_is_stored_unchanged(): void
    {
        // If Long Châu moves its files, serving the original beats serving nothing.
        $product = LongChauProduct::fromNextData($this->nextData([
            'primaryImage' => ['url' => 'https://images.example.com/v2/other/panadol.png'],
        ]));

        $this->assertSame('https://images.example.com/v2/other/panadol.png', $product->imageUrl);
    }

    public function test_an_already_resized_url_is_not_wrapped_twice(): void
    {
        $url = 'https://cdn.nhathuoclongchau.com.vn/unsafe/425x0/filters:quality(90):format(webp)/panadol.png';

        $product = LongChauProduct::fromNextData($this->nextData([
            'primaryImage' => ['url' => $url],
        ]));

        $this->assertSame($url, $product->imageUrl);
    }

    /**
     * A minimal but valid product payload, overridable per assertion.
     */
    private function nextData(array $overrides = []): array
    {
        return ['props' => ['pageProps' => ['product' => array_merge([
            'sku' => '00005713',
            'webName' => 'Viên nén Panadol Extra đỏ',
            'prices' => [['level' => 1, 'price' => 252000]],
            'categories' => [
                ['level' => 1, 'name' => 'Thuốc'],
                ['level' => 2, 'name' => 'Thuốc giảm đau, hạ sốt, kháng viêm'],
            ],
            'ingredient' => [['name' => 'Paracetamol', 'shortDescription' => '500mg']],
            'expirationDate' => '36 tháng',
            'prescription' => false,
        ], $overrides)]]];
    }
}
