<?php

namespace Tests\Unit\Services;

use App\Services\ChatbotService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A self-introduction must yield a name, and nothing else.
 *
 * Every sentence of the form "tôi là X" used to hand X back as the customer's
 * name, so "tôi là nam hay nữ?" produced the name "Nam" and the assistant greeted
 * them by it. Gender, occupations and questions are not names.
 */
class ChatbotUserNameExtractionTest extends TestCase
{
    protected ChatbotService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ChatbotService::class);
    }

    protected function extract(string $message): string
    {
        $method = new ReflectionMethod($this->service, 'extractUserNameFromSelfIntroduction');
        $method->setAccessible(true);

        return (string) $method->invoke($this->service, $message);
    }

    public function test_a_real_name_is_still_extracted(): void
    {
        $this->assertSame('Hùng', $this->extract('tôi tên là Hùng'));
        $this->assertSame('Nam', $this->extract('tôi là Nam'));
        $this->assertSame('Lan', $this->extract('mình tên Lan'));
    }

    public function test_a_gender_question_is_not_a_name(): void
    {
        $this->assertSame('', $this->extract('tôi là nam hay nữ?'));
        $this->assertSame('', $this->extract('toi la nam hay nu'));
    }

    public function test_a_gender_word_alone_is_not_a_name(): void
    {
        // "Nam" is a common Vietnamese given name, so a lone "tôi là nam" stays a
        // name. The gender statement is only rejected as a question (test above).
        $this->assertSame('Nam', $this->extract('tôi là nam'));
        $this->assertSame('', $this->extract('mình là nữ'));
        $this->assertSame('', $this->extract('em là nữ ạ'));
    }

    public function test_an_occupation_is_not_a_name(): void
    {
        $this->assertSame('', $this->extract('tôi là bác sĩ'));
        $this->assertSame('', $this->extract('tôi là sinh viên'));
        $this->assertSame('', $this->extract('tôi là dược sĩ'));
        $this->assertSame('', $this->extract('tôi là giáo viên'));
    }

    public function test_a_question_is_not_a_name(): void
    {
        $this->assertSame('', $this->extract('tôi là ai?'));
        $this->assertSame('', $this->extract('tôi là gì'));
    }

    public function test_a_sentence_after_the_name_is_not_part_of_it(): void
    {
        // The name is the first token; "và tôi cần mua thuốc" is the request.
        $this->assertSame('Hùng', $this->extract('tôi là Hùng và tôi cần mua thuốc ho'));
    }
}
