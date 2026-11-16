<?php

namespace Tests\Unit\Services;

use App\Services\ChatbotService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The assistant must never invent a product URL.
 *
 * A turn that does not call the catalogue tool hands the model an empty product
 * list. It still remembers ids from earlier turns and writes links of its own
 * ("[Sản phẩm 322](http://localhost/products/322)") — wrong host, wrong label,
 * and a product the current answer never retrieved.
 */
class ChatbotBaoVeLinkSanPhamTest extends TestCase
{
    protected ChatbotService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ChatbotService::class);
    }

    protected function strip(string $text): string
    {
        $method = new ReflectionMethod($this->service, 'stripInventedProductLinks');
        $method->setAccessible(true);

        return (string) $method->invoke($this->service, $text);
    }

    public function test_link_markdown_den_san_pham_bi_xoa(): void
    {
        $text = 'Huy có thể mở: - [Sản phẩm 322](http://localhost/products/322) nhé.';

        $this->assertStringNotContainsString('/products/322', $this->strip($text));
        $this->assertStringNotContainsString('[Sản phẩm 322]', $this->strip($text));
    }

    public function test_nhieu_link_biet_ton_deu_bien_mat(): void
    {
        $text = implode("\n", [
            '- [Sản phẩm 322](http://localhost/products/322)',
            '- [Sản phẩm 974](http://localhost/products/974)',
            '- [Sản phẩm 1109](http://localhost/products/1109)',
            '- [Sản phẩm 1069](http://localhost/products/1069)',
        ]);

        $stripped = $this->strip($text);

        $this->assertStringNotContainsString('/products/', $stripped);
    }

    public function test_url_san_pham_truc_tiep_bi_xoa(): void
    {
        $text = 'Xem thêm tại http://localhost/products/322 nhé.';

        $this->assertStringNotContainsString('/products/322', $this->strip($text));
    }

    public function test_url_san_pham_tuong_doi_truc_tiep_bi_xoa(): void
    {
        $text = 'Mở /products/322 để xem.';

        $this->assertStringNotContainsString('/products/322', $this->strip($text));
    }

    public function test_van_ban_thuong_giu_nguyen(): void
    {
        $text = 'Dạ, em không có khả năng mở trình duyệt ạ. Huy cho em biết thêm triệu chứng nhé.';

        $this->assertSame($text, $this->strip($text));
    }

    public function test_url_khong_phai_san_pham_giu_nguyen(): void
    {
        $text = 'Xem danh mục tại http://localhost/products?category=1 nhé.';

        $this->assertSame($text, $this->strip($text));
    }
}
