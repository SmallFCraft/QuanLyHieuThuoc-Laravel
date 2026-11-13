<?php

namespace Tests\Unit\Support;

use App\Services\AnToanThuocService;
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

    public function test_mo_phong_du_cac_truong_san_pham_tu_trang_thuc(): void
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

    public function test_lay_gia_hop_chu_khong_lay_gia_theo_don_vi(): void
    {
        // prices[] on the fixture is [Hộp 252000, Vỉ 16800, Viên 1400]. The row
        // describes a box, so the box price is the only one that matches it.
        $this->assertSame(252000, LongChauProduct::fromHtml($this->fixture('panadol.html'))->price);
    }

    public function test_san_pham_khong_cong_bo_gia_ban_tu_choi(): void
    {
        // Vietnamese law bars listing prescription drug prices online, so Long Châu
        // sends price: null for every unit. products.price is NOT NULL.
        $this->assertNull(LongChauProduct::fromHtml($this->fixture('bisoloc-no-price.html')));
    }

    public function test_thieu_next_data_bao_cao_rieng_khoi_san_pham_khong_dung_duoc(): void
    {
        $this->assertNull(LongChauProduct::extractNextData('<html><body>404</body></html>'));
        $this->assertIsArray(LongChauProduct::extractNextData($this->fixture('panadol.html')));
    }

    public function test_thanh_phan_dung_dinh_dang_trich_xuat_hoat_chat_doc_duoc(): void
    {
        $product = LongChauProduct::fromHtml($this->fixture('panadol.html'));

        $this->assertSame('Caffeine 65mg, Paracetamol 500mg', $product->ingredients);

        // The cart interaction check reads products.ingredients, so the crawler's
        // format has to survive its tokeniser or every warning goes silent.
        $extracted = app(AnToanThuocService::class)->trichXuatHoatChat($product->ingredients);

        $this->assertContains('paracetamol', $extracted);
        $this->assertContains('caffeine', $extracted);
    }

    public function test_cach_dung_la_van_ban_thuan_chua_ca_chi_dinh_va_lieu_dung(): void
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
    public function test_doc_han_su_dung_long_chau_cong_bo(?string $raw, ?int $expected): void
    {
        $product = LongChauProduct::fromNextData($this->nextData(['expirationDate' => $raw]));

        // Null, never a default: this figure is shown to customers as the
        // manufacturer's, so a guess must not be presented as one.
        $this->assertSame($expected, $product->shelfLifeMonths);
    }

    public function test_lo_du_phong_van_co_ngay_het_han_khi_khong_cong_bo_han_su_dung(): void
    {
        $product = LongChauProduct::fromNextData($this->nextData(['expirationDate' => null]));

        $this->assertNull($product->shelfLifeMonths);
        $this->assertSame(
            '2028-08-30',
            $product->expirationDate(Carbon::parse('2026-08-30'))->toDateString(),
            'products.expiration_date is NOT NULL, so the batch still needs a date.'
        );
    }

    public function test_so_lo_bat_xuat_tu_sku_de_crawl_lai_dung_lai_so_lo(): void
    {
        $product = LongChauProduct::fromHtml($this->fixture('panadol.html'));

        // No date component on purpose: the importer matches rows on batch_number,
        // so a month-stamped value would insert a duplicate product every month.
        $this->assertSame('LC-00005713', $product->batchNumber());
    }

    public function test_ngay_het_han_theo_han_su_dung_da_cong_bo(): void
    {
        $product = LongChauProduct::fromHtml($this->fixture('panadol.html'));

        $this->assertSame(
            '2029-08-30',
            $product->expirationDate(Carbon::parse('2026-08-30'))->toDateString()
        );
    }

    public function test_san_pham_thieu_ten_danh_muc_hoac_sku_ban_tu_choi(): void
    {
        $this->assertNull(LongChauProduct::fromNextData($this->nextData(['webName' => '', 'name' => ''])));
        $this->assertNull(LongChauProduct::fromNextData($this->nextData(['sku' => null])));
        $this->assertNull(LongChauProduct::fromNextData($this->nextData(['categories' => []])));
    }

    public function test_gia_ngoai_khoang_luu_duoc_ban_tu_choi(): void
    {
        $this->assertNull(LongChauProduct::fromNextData($this->nextData([
            'prices' => [['level' => 1, 'price' => 0]],
        ])));
        $this->assertNull(LongChauProduct::fromNextData($this->nextData([
            'prices' => [['level' => 1, 'price' => 100_000_000]],
        ])));
    }

    public function test_du_phong_ve_go_nho_hon_khi_hop_khong_co_gia(): void
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

    public function test_chuoi_qua_dai_tu_nguon_khong_lam_vo_insert(): void
    {
        $product = LongChauProduct::fromNextData($this->nextData([
            'webName' => str_repeat('a', 400),
            'primaryImage' => ['url' => 'https://cdn.nhathuoclongchau.com.vn/v1/static/' . str_repeat('b', 300) . '.png'],
        ]));

        $this->assertLessThanOrEqual(255, mb_strlen($product->name));
        $this->assertNull($product->imageUrl);
    }

    public function test_hinh_anh_yeu_cau_qua_endpoint_resize_cdn(): void
    {
        // The originals are 1.5-3.5 MB each. Long Châu's own resize endpoint serves
        // the same image as webp at 40-100 KB, which is what a product card needs.
        $product = LongChauProduct::fromHtml($this->fixture('panadol.html'));

        $this->assertSame(
            'https://cdn.nhathuoclongchau.com.vn/unsafe/600x0/filters:quality(90):format(webp)/DSC_099842_74c1fc532a.png',
            $product->imageUrl
        );
    }

    public function test_duong_dan_hinh_lom_giu_nguyen_thu_muc_con(): void
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

    public function test_host_hoac_duong_dan_hinh_bat_ngo_du_luu_nguyen(): void
    {
        // If Long Châu moves its files, serving the original beats serving nothing.
        $product = LongChauProduct::fromNextData($this->nextData([
            'primaryImage' => ['url' => 'https://images.example.com/v2/other/panadol.png'],
        ]));

        $this->assertSame('https://images.example.com/v2/other/panadol.png', $product->imageUrl);
    }

    public function test_url_da_resize_khong_bi_boc_ma_lan_nua(): void
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
