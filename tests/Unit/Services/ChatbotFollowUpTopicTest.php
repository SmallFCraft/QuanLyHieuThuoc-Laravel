<?php

namespace Tests\Unit\Services;

use App\Services\ChatbotService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * "cho xem link sản phẩm đi" carries no topic of its own.
 *
 * It refers to the products the previous turn just listed. Resolved as a fresh
 * search it became the literal query "cho link sản phẩm đi", matched nothing, and
 * the model — given an empty product list — wrote its own links instead:
 * "http://localhost/products/322", missing the dev port and pointing at a product
 * this answer never looked up.
 */
class ChatbotFollowUpTopicTest extends TestCase
{
    protected ChatbotService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ChatbotService::class);
    }

    protected function isElliptical(string $message): bool
    {
        $method = new ReflectionMethod($this->service, 'isEllipticalFollowUpMessage');
        $method->setAccessible(true);

        return (bool) $method->invoke($this->service, $message);
    }

    public function test_a_request_for_the_link_is_a_follow_up(): void
    {
        $this->assertTrue($this->isElliptical('cho xem link sản phẩm đi'));
        $this->assertTrue($this->isElliptical('link đi'));
        $this->assertTrue($this->isElliptical('cho tôi xin link'));
    }

    public function test_a_request_to_see_details_is_a_follow_up(): void
    {
        $this->assertTrue($this->isElliptical('xem chi tiết đi'));
        $this->assertTrue($this->isElliptical('cho xem thêm'));
    }

    public function test_a_message_with_its_own_topic_is_not_a_follow_up(): void
    {
        $this->assertFalse($this->isElliptical('paracetamol'));
        $this->assertFalse($this->isElliptical('cho tôi xem link paracetamol'));
        $this->assertFalse($this->isElliptical('giá thuốc ho bao nhiêu'));
    }

    /**
     * "tóm tắt lại theo dạng table" names a format, not a product.
     *
     * Searched as its own query it became "tóm tắt", which the catalogue matched
     * against seven unrelated products (Photomit, a baby formula, a sheet mask), and
     * the PHP answer builder replied before the model was ever called — so a request
     * for a table got a numbered list.
     */
    public function test_a_request_to_reformat_as_a_table_is_a_follow_up(): void
    {
        $this->assertTrue($this->isElliptical('tóm tắt lại theo dạng table giúp tôi nhé'));
        $this->assertTrue($this->isElliptical('tóm tắt lại thành bảng'));
        $this->assertTrue($this->isElliptical('cho tôi xin bảng tóm tắt'));
        $this->assertTrue($this->isElliptical('dạng bảng đi'));
    }
}
