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
class ChatbotProductLinkGuardTest extends TestCase
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

    public function test_a_markdown_link_to_a_product_is_removed(): void
    {
        $text = 'Huy có thể mở: - [Sản phẩm 322](http://localhost/products/322) nhé.';

        $this->assertStringNotContainsString('/products/322', $this->strip($text));
        $this->assertStringNotContainsString('[Sản phẩm 322]', $this->strip($text));
    }

    public function test_several_invented_links_all_go(): void
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

    public function test_a_bare_product_url_is_removed(): void
    {
        $text = 'Xem thêm tại http://localhost/products/322 nhé.';

        $this->assertStringNotContainsString('/products/322', $this->strip($text));
    }

    public function test_a_bare_relative_product_url_is_removed(): void
    {
        $text = 'Mở /products/322 để xem.';

        $this->assertStringNotContainsString('/products/322', $this->strip($text));
    }

    public function test_ordinary_prose_survives_untouched(): void
    {
        $text = 'Dạ, em không có khả năng mở trình duyệt ạ. Huy cho em biết thêm triệu chứng nhé.';

        $this->assertSame($text, $this->strip($text));
    }

    public function test_a_non_product_url_is_left_alone(): void
    {
        $text = 'Xem danh mục tại http://localhost/products?category=1 nhé.';

        $this->assertSame($text, $this->strip($text));
    }
}
