<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Services\AuditLogService;
use App\Services\ChatbotService;
use App\Services\CheckoutService;
use App\Services\AiProviderService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ChatbotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ChatbotService $chatbotService;
    protected MockObject $aiProviderService;

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiProviderService = $this->createMock(AiProviderService::class);
        $inventoryService = $this->createMock(InventoryService::class);
        $checkoutService = $this->createMock(CheckoutService::class);
        $auditLogService = $this->createMock(AuditLogService::class);

        $this->chatbotService = new ChatbotService(
            $this->aiProviderService,
            $inventoryService,
            $checkoutService,
            $auditLogService
        );
    }

    public function test_search_products_returns_at_most_5_products(): void
    {
        $category = Category::factory()->create();

        Product::factory()->count(10)->create([
            'MaDM' => $category->MaDM,
            'name' => 'Paracetamol 500mg',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
        ]);

        $results = $this->chatbotService->searchProducts('Paracetamol');

        $this->assertLessThanOrEqual(5, $results->count());
    }

    public function test_search_products_only_returns_in_stock_products(): void
    {
        $category = Category::factory()->create();

        Product::factory()->count(3)->create([
            'MaDM' => $category->MaDM,
            'name' => 'Aspirin',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        Product::factory()->count(2)->create([
            'MaDM' => $category->MaDM,
            'name' => 'Aspirin',
            'stock_quantity' => 0,
            'expiration_date' => now()->addYear(),
        ]);

        $results = $this->chatbotService->searchProducts('Aspirin');

        $this->assertEquals(3, $results->count());
        foreach ($results as $product) {
            $this->assertGreaterThan(0, $product->stock_quantity);
        }
    }

    public function test_search_products_only_returns_non_expired_products(): void
    {
        $category = Category::factory()->create();

        Product::factory()->count(3)->create([
            'MaDM' => $category->MaDM,
            'name' => 'Ibuprofen',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        Product::factory()->count(2)->create([
            'MaDM' => $category->MaDM,
            'name' => 'Ibuprofen',
            'stock_quantity' => 50,
            'expiration_date' => now()->subDay(),
        ]);

        $results = $this->chatbotService->searchProducts('Ibuprofen');

        $this->assertEquals(3, $results->count());
        foreach ($results as $product) {
            $this->assertTrue($product->expiration_date->isFuture());
        }
    }

    public function test_search_products_applies_fefo_ordering(): void
    {
        $category = Category::factory()->create();

        $product1 = Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin C',
            'stock_quantity' => 50,
            'expiration_date' => now()->addMonths(6),
        ]);

        $product2 = Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin C',
            'stock_quantity' => 50,
            'expiration_date' => now()->addMonths(3),
        ]);

        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin C',
            'stock_quantity' => 50,
            'expiration_date' => now()->addMonths(9),
        ]);

        $results = $this->chatbotService->searchProducts('Vitamin C');

        $this->assertEquals($product2->MaSP, $results->first()->MaSP);
        $this->assertNotEquals($product1->MaSP, $results->first()->MaSP);
    }

    public function test_search_products_searches_multiple_fields(): void
    {
        $category = Category::factory()->create();

        $productByName = Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Other ingredients',
            'usage' => 'Other usage',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $productByIngredients = Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Pain Relief',
            'ingredients' => 'Contains Paracetamol',
            'usage' => 'Other usage',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $productByUsage = Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Fever Medicine',
            'ingredients' => 'Other ingredients',
            'usage' => 'Use Paracetamol for fever',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $results = $this->chatbotService->searchProducts('Paracetamol');

        $this->assertEquals(3, $results->count());
        $this->assertEqualsCanonicalizing(
            [$productByName->MaSP, $productByIngredients->MaSP, $productByUsage->MaSP],
            $results->pluck('MaSP')->all()
        );
    }

    public function test_search_products_loads_category_relationship(): void
    {
        $category = Category::factory()->create(['name' => 'Pain Relief']);

        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Aspirin',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $results = $this->chatbotService->searchProducts('Aspirin');

        $this->assertNotEmpty($results);
        $this->assertTrue($results->first()->relationLoaded('category'));
        $this->assertEquals('Pain Relief', $results->first()->category->name);
    }

    public function test_search_products_returns_empty_when_no_matches(): void
    {
        $results = $this->chatbotService->searchProducts('NonexistentProduct');

        $this->assertEmpty($results);
    }

    public function test_get_system_prompt_mentions_products_categories_and_safety_rules(): void
    {
        $prompt = $this->chatbotService->getSystemPrompt();

        $this->assertStringContainsString('Medicare Pharmacy', $prompt);
        $this->assertStringContainsString('Grounding policy', $prompt);
        $this->assertStringContainsString('Tone policy', $prompt);
        $this->assertStringContainsString('Không nêu vendor/model/toolchain nội bộ', $prompt);
        $this->assertStringContainsString('Không tự nhận có internet', $prompt);
        $this->assertStringContainsString('search_products', $prompt);
    }

    public function test_function_schema_has_search_products_function(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('getFunctionSchema');
        $method->setAccessible(true);

        $schema = $method->invoke($this->chatbotService);

        $this->assertEquals('search_products', $schema[0]['name']);
        $this->assertEquals('string', $schema[0]['parameters']['properties']['query']['type']);
        $this->assertArrayHasKey('category', $schema[0]['parameters']['properties']);
        $this->assertArrayHasKey('symptoms', $schema[0]['parameters']['properties']);
    }

    public function test_validate_message_does_not_block_cong_dung_as_bad_word(): void
    {
        $message = "v\u{1EAD}y c\u{00F2}n thu\u{1ED1}c n\u{00E0}o b\u{1ED5} m\u{1EAF}t kh\u{00F4}ng? n\u{1EBF}u c\u{00F3} cho v\u{00ED} d\u{1EE5}? h\u{00E3}y cho t\u{00F4}i th\u{00EA}m c\u{00F4}ng d\u{1EE5}ng";

        $result = $this->chatbotService->validateMessage($message);

        $this->assertTrue($result['valid']);
    }

    public function test_validate_message_still_blocks_exact_blacklisted_token(): void
    {
        $result = $this->chatbotService->validateMessage("m\u{00E0}y ngu");

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['reason']);
    }

    public function test_process_message_uses_local_product_lookup_without_ai_provider(): void
    {
        $category = Category::factory()->create(['name' => 'Giảm đau']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('mua paracetamol', 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertStringContainsString('Paracetamol 500mg', $result['response']);
    }

    public function test_process_message_uses_recent_image_product_for_demonstrative_follow_up(): void
    {
        $category = Category::factory()->create(['name' => 'Vitamin']);
        $product = Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin E 400IU',
            'usage' => 'Bổ sung vitamin E, hỗ trợ chống oxy hóa.',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage(
            'thuốc đó có công dụng gì?',
            1,
            [
                'recent_image_products' => [
                    [
                        'matched' => true,
                        'product' => [
                            'id' => $product->MaSP,
                            'name' => 'Vitamin E 400IU',
                        ],
                    ],
                ],
            ]
        );

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertSame('Vitamin E 400IU', $result['products'][0]['name']);
        $this->assertStringContainsString('sản phẩm vừa nhận dạng từ ảnh', $result['response']);
        $this->assertStringContainsString('Vitamin E 400IU', $result['response']);
        $this->assertTrue($result['debug']['resolved_from_recent_image_products'] ?? false);
    }

    public function test_process_message_returns_symptom_guidance_without_product_lookup_for_general_symptom_question(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("T\u{00F4}i b\u{1ECB} ti\u{00EA}u ch\u{1EA3}y th\u{00EC} sao?", 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertStringContainsString('ORESOL', $result['response']);
    }

    public function test_process_message_does_not_search_catalog_for_general_date_question(): void
    {
        $aiAnswer = "H\u{00F4}m nay l\u{00E0} ng\u{00E0}y 06/07/2026.";

        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $options): bool => $options === [])
            )
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => $aiAnswer,
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage("h\u{00F4}m nay l\u{00E0} ng\u{00E0}y g\u{00EC}?", 1, []);

        $this->assertTrue($result['success']);
        $this->assertSame($aiAnswer, $result['response']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertFalse($result['show_disclaimer']);
    }

    public function test_process_message_answers_colloquial_diarrhea_question_without_catalog_fallback(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('ỉa chảy có cần ỉa ko?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertStringContainsString('không nên cố nhịn đi ngoài', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('chưa thấy sản phẩm khớp hoàn toàn', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('từ khóa', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_uses_previous_symptom_context_for_prolonged_abdominal_pain_follow_up(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage(
            'triệu chứng của tôi đang kéo dài cả tuần này rồi, cần bạn hỗ trợ gấp',
            1,
            [
                'previous_messages' => [
                    [
                        'role' => 'user',
                        'content' => 'đau bụng cấp mãn tính thì sao?',
                    ],
                    [
                        'role' => 'assistant',
                        'content' => 'Anh/chị mô tả thêm giúp em thời gian bị và triệu chứng đi kèm nhé.',
                    ],
                ],
            ]
        );

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertStringContainsString('đau bụng kéo dài cả tuần', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringContainsString('đi khám sớm trong hôm nay', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('theo dõi diễn tiến trong ngắn hạn', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_blocks_catalog_lookup_for_urgent_medical_context(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $message = "Hello, are you oke? t\u{00F4}i b\u{1ECB} \u{1EC9}a ch\u{1EA3}y c\u{1EA5}p \u{0111}\u{1ED9} 9, v\u{00E0} b\u{1ECB} ung th\u{01B0} giai \u{0111}o\u{1EA1}n cu\u{1ED1}i. n\u{00EA}n s\u{1EED} d\u{1EE5}ng sao \u{0111}\u{00E2}y??";

        $result = $this->chatbotService->processMessage($message, 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertStringContainsString('cơ sở y tế', $result['response']);
        $this->assertStringContainsString('ung thư', $result['response']);
        $this->assertStringNotContainsString('chưa thấy sản phẩm', $result['response']);
        $this->assertStringNotContainsString('từ khóa', $result['response']);
    }

    public function test_process_message_handles_dengue_fever_as_urgent_medical_context(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('tôi bị sốt xuất huyết nên làm gì?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertStringContainsString('sốt xuất huyết', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringContainsString('không nên tự chọn thuốc', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringContainsString('cấp cứu', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_answers_cause_question_for_diarrhea_and_stomach_pain(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('Vậy tiêu chảy, đau dạ dày là do đâu?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertStringContainsString('nguyên nhân', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringContainsString('rối loạn tiêu hóa', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringContainsString('đau dạ dày', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_blocks_generic_product_follow_up_after_dengue_context(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('sản phẩm?', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'tôi bị sốt xuất huyết nên làm gì?',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Anh/chị nên đi khám sớm.',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertStringContainsString('sốt xuất huyết', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringContainsString('không nên tự chọn thuốc', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_blocks_generic_medicine_or_product_request_after_dengue_context(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('tôi cần thuốc hoặc sản phẩm', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'tôi bị sốt xuất huyết nên làm gì?',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Anh/chị nên đi khám sớm.',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertStringContainsString('sốt xuất huyết', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringContainsString('không nên tự chọn thuốc', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('hoac san pham', $result['response']);
        $this->assertStringNotContainsString('chưa thấy sản phẩm khớp', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_provider_failure_does_not_return_random_products(): void
    {
        $category = Category::factory()->create(['name' => 'Tiêu hóa']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Smecta',
            'usage' => 'Điều trị tiêu chảy.',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willThrowException(new \RuntimeException('Your project has been denied access. Please contact support.'));

        $result = $this->chatbotService->processMessage('tôi cần thuốc hoặc sản phẩm', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertSame('auth_or_access_denied', $result['debug']['provider_error_type'] ?? null);
        $this->assertFalse($result['debug']['used_product_suggestions'] ?? true);
        $this->assertStringContainsString('kết nối AI', $result['response']);
    }

    public function test_process_message_returns_website_intro_without_product_search(): void
    {
        $category = Category::factory()->create([
            'name' => 'Vitamin',
            'description' => 'Nhóm sản phẩm bổ sung vitamin',
        ]);

        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin C 1000mg',
            'stock_quantity' => 10,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("Website n\u{00E0}y l\u{00E0} g\u{00EC}?", 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertNotEmpty($result['categories']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringNotContainsString('L? Huy', $result['response']);
        $this->assertStringContainsString('website', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_answers_identity_question_locally_without_calling_ai_provider(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('bạn là model gì?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringNotContainsString('L? Huy', $result['response']);
        $this->assertStringNotContainsString('nhà cung cấp nội bộ', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('Google', $result['response']);
        $this->assertStringNotContainsString('mô hình ngôn ngữ lớn', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_answers_vendor_question_locally_without_confirming_vendor(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('bạn dùng provider nào?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringNotContainsString('L? Huy', $result['response']);
        $this->assertStringNotContainsString('nhà cung cấp nội bộ', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('Google', $result['response']);
    }

    public function test_process_message_answers_internet_access_question_locally_without_calling_ai_provider(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('bạn có truy cập internet không?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringNotContainsString('L? Huy', $result['response']);
        $this->assertStringContainsString('không tự truy cập internet', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_answers_google_maps_question_locally_without_calling_ai_provider(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('bạn có xem Google Maps được không?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertStringContainsString('Google Maps', $result['response']);
        $this->assertStringContainsString('không tự truy cập internet', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_handles_website_intro_with_product_lookup(): void
    {
        $category = Category::factory()->create(['name' => 'Tiêu hóa']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Smecta',
            'usage' => 'Hỗ trợ giảm tiêu chảy cấp và rối loạn tiêu hóa.',
            'stock_quantity' => 15,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("Website n\u{00E0}y l\u{00E0} g\u{00EC}? c\u{00F3} thu\u{1ED1}c smecta ko?", 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertEquals('Smecta', $result['products'][0]['name']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringNotContainsString('L? Huy', $result['response']);
        $this->assertStringContainsString('Smecta', $result['response']);
    }

    public function test_process_message_handles_website_intro_with_product_usage_question(): void
    {
        $category = Category::factory()->create(['name' => 'Tiêu hóa']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Smecta',
            'usage' => 'Hỗ trợ giảm tiêu chảy cấp và rối loạn tiêu hóa.',
            'stock_quantity' => 15,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("Website n\u{00E0}y l\u{00E0} g\u{00EC}? c\u{00F3} thu\u{1ED1}c smecta ko? C\u{00F3} c\u{00F4}ng d\u{1EE5}ng hay t\u{00E1}c d\u{1EE5}ng g\u{00EC}?", 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertStringContainsString('Smecta', $result['response']);
        $this->assertStringContainsString('tiêu chảy', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_category_lookup_response_returns_categories_without_ai_provider(): void
    {
        $category = Category::factory()->create([
            'name' => 'Vitamin',
            'description' => 'Nhóm sản phẩm hỗ trợ bổ sung vitamin',
        ]);

        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin C 1000mg',
            'stock_quantity' => 10,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('buildCategoryLookupResponse');
        $method->setAccessible(true);

        $result = $method->invoke($this->chatbotService, 'xem danh mục vitamin');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['categories']);
        $this->assertEquals('Vitamin', $result['categories'][0]['name']);
        $this->assertStringContainsString('danh mục', $result['response']);
    }

    public function test_process_message_uses_ai_provider_for_symptom_queries_and_returns_disclaimer(): void
    {
        $category = Category::factory()->create(['name' => 'Giảm đau']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'function_call' => [
                                'name' => 'search_products',
                                'arguments' => json_encode(['query' => 'Paracetamol', 'symptoms' => ['đau đầu']]),
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('đau đầu nên dùng gì', 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertNotEmpty($result['explanations']);
        $this->assertArrayHasKey('show_disclaimer', $result);
    }

    public function test_process_message_falls_back_to_symptom_keywords_when_generic_query_has_no_direct_match(): void
    {
        $category = Category::factory()->create(['name' => 'Tiêu hóa']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Smecta',
            'usage' => 'Hỗ trợ giảm tiêu chảy cấp và rối loạn tiêu hóa.',
            'stock_quantity' => 30,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'function_call' => [
                                'name' => 'search_products',
                                'arguments' => json_encode([
                                    'query' => 'hỗ trợ tiêu hóa',
                                    'symptoms' => ['táo bón', 'tiêu chảy'],
                                ]),
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('Có sản phẩm nào hỗ trợ táo bón hay tiêu chảy?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertContains('Smecta', array_column($result['products'], 'name'));
    }

    public function test_process_message_uses_previous_symptoms_for_generic_product_follow_up(): void
    {
        $category = Category::factory()->create(['name' => 'Tiêu hóa']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Smecta',
            'usage' => 'Hỗ trợ giảm tiêu chảy cấp và rối loạn tiêu hóa.',
            'stock_quantity' => 30,
            'expiration_date' => now()->addYear(),
        ]);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Lactulose Syrup',
            'usage' => 'Hỗ trợ táo bón và làm mềm phân.',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Em sẽ dựa trên triệu chứng trước đó để gợi ý sản phẩm phù hợp.',
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('có thuốc gì ko?', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'tôi bị đau bụng ỉa chảy cấp mãng tính, lâu ngày táo bón, ỉa ko ra hơi',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Nếu anh/chị muốn em gợi ý sản phẩm hỗ trợ, hãy nhắn thêm triệu chứng chủ đạo.',
                ],
            ],
        ]);

        $productNames = array_column($result['products'], 'name');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertContains('Smecta', $productNames);
        $this->assertContains('Lactulose Syrup', $productNames);
        $this->assertTrue($result['show_disclaimer']);
    }

    public function test_process_message_extracts_multiple_health_goals_from_generic_question(): void
    {
        $category = Category::factory()->create(['name' => 'Thực phẩm chức năng']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Canxi Kid Plus',
            'usage' => 'Hỗ trợ tăng chiều cao và phát triển xương cho trẻ.',
            'stock_quantity' => 25,
            'expiration_date' => now()->addYear(),
        ]);

        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin C Immunity',
            'usage' => 'Hỗ trợ tăng sức đề kháng và giảm mệt mỏi.',
            'stock_quantity' => 40,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('Có sản phẩm nào hỗ trợ tăng chiều cao hay tăng sức đề kháng?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertContains('Canxi Kid Plus', array_column($result['products'], 'name'));
        $this->assertContains('Vitamin C Immunity', array_column($result['products'], 'name'));
    }

    public function test_process_message_does_not_misclassify_ho_tro_giam_dau_as_capability_question(): void
    {
        $category = Category::factory()->create(['name' => 'Thuốc giảm đau - Hạ sốt']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Efferalgan 500mg',
            'usage' => 'Giảm đau, hạ sốt nhanh.',
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'function_call' => [
                                'name' => 'search_products',
                                'arguments' => json_encode([
                                    'query' => 'sản phẩm nào hỗ trợ thuốc giảm đau hạ sốt',
                                    'symptoms' => ['đau', 'sốt'],
                                ]),
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('Có sản phẩm nào hỗ trợ giảm đau, hạ sốt?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertContains('Efferalgan 500mg', array_column($result['products'], 'name'));
        $this->assertStringNotContainsString('em có thể hỗ trợ anh/chị tìm sản phẩm, gợi ý danh mục phù hợp', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_ignores_meaningless_catalog_query_and_does_not_return_random_products(): void
    {
        $category = Category::factory()->create(['name' => 'Thuốc tim mạch']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Isosorbide Dinitrate 5mg',
            'usage' => 'Điều trị đau thắt ngực.',
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Dạ, em có thể hỗ trợ các nội dung liên quan đến nhà thuốc.',
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('Tao là tao', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertStringNotContainsString('với từ khóa', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('Isosorbide Dinitrate 5mg', $result['response']);
    }

    public function test_process_message_handles_self_introduction_without_catalog_fallback(): void
    {
        $category = Category::factory()->create(['name' => 'Thuốc tim mạch']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Isosorbide Dinitrate 5mg',
            'usage' => 'Điều trị đau thắt ngực.',
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('tôi tên là Huy', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertStringContainsString('Huy', $result['response']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringNotContainsString('L? Huy', $result['response']);
        $this->assertStringNotContainsString('anh/chị Huy', $result['response']);
        $this->assertStringNotContainsString('chưa thấy sản phẩm', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('từ khóa', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('Isosorbide Dinitrate 5mg', $result['response']);
    }

    public function test_process_message_preserves_diacritics_in_declared_name(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('tôi tên là Bằng', 1, []);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Bằng', $result['response']);
        $this->assertStringNotContainsString('Bang', $result['response']);
    }

    public function test_process_message_recalls_declared_name_from_history(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('tôi tên là gì?', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'tôi tên là Huy',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Dạ, em chào Huy.',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertStringContainsString('Huy', $result['response']);
        $this->assertStringNotContainsString('chào Gì', $result['response']);
        $this->assertStringNotContainsString('chào Gi', $result['response']);
        $this->assertStringNotContainsString('chưa thấy sản phẩm', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_recalls_declared_name_from_session_memory_when_recent_context_drops_it(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('toi ten la gi?', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'thuoc vitamin c co dac trung gi khong?',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Da, voi tu khoa vitamin c, em tim thay san pham Vitamin C 1000mg.',
                ],
            ],
            'name_memory_messages' => [
                [
                    'role' => 'user',
                    'content' => 'minh la Huy',
                ],
                [
                    'role' => 'user',
                    'content' => 'cho toi xin 1 bat com',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertStringContainsString('Huy', $result['response']);
        $this->assertStringNotContainsString('Gi', $result['response']);
        $this->assertStringNotContainsString('chua thay san pham', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_name_question_without_history_does_not_learn_gi_as_name(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('tôi tên là gì?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertStringContainsString('chưa thấy', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('chào Gì', $result['response']);
        $this->assertStringNotContainsString('chào Gi', $result['response']);
    }

    public function test_process_message_treats_product_named_query_as_catalog_lookup_not_self_introduction(): void
    {
        $category = Category::factory()->create(['name' => 'Vitamin']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin C 1000mg',
            'usage' => 'Bổ sung vitamin C.',
            'stock_quantity' => 120,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('thuốc có tên là Vitamin C', 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertStringContainsString('Vitamin C 1000mg', $result['response']);
        $this->assertStringNotContainsString('em chào Vitamin C', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('tra cứu Vitamin C', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_does_not_append_catalog_miss_for_product_safety_follow_up(): void
    {
        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Dạ, các loại vitamin thường an toàn khi dùng đúng liều. Nếu anh/chị có bệnh nền, đang dùng thuốc khác hoặc muốn dùng liều cao thì nên hỏi dược sĩ/bác sĩ trước.',
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('uống vào có bị làm sao không?', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'thuốc vitamin',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Dạ, với từ khóa `vitamin`, em tìm thấy các sản phẩm sau: Vitamin C 1000mg, Vitamin E 400IU, Vitamin B Complex.',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertStringContainsString('vitamin', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('chưa thấy sản phẩm khớp', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('từ khóa', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_does_not_append_catalog_miss_for_product_usage_timing_follow_up(): void
    {
        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Da, vitamin C thuong nen uong sau an, tot nhat la sau bua sang hoac bua trua. Neu san pham ghi 1 vien/ngay thi dung 1 vien/ngay sau an.',
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('vay toi nen uong khi nao? an khi nao?', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'thuoc vitamin c co dac trung gi khong?',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Da, voi tu khoa `vitamin c`, em tim thay san pham Vitamin C 1000mg.',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertTrue($result['debug']['ai_provider_used'] ?? false);
        $this->assertStringContainsString('sau an', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('chua thay san pham', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('tu khoa', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('vay nen uong khi an khi', mb_strtolower($result['response'], 'UTF-8'));
    }
    public function test_process_message_uses_declared_name_from_history_in_later_local_response(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('bạn có thể làm gì?', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'tôi tên là Huy',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Dạ, em chào Huy.',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Huy', $result['response']);
        $this->assertStringNotContainsString('anh/chị', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_build_conversation_messages_includes_declared_name_context(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('buildConversationMessages');
        $method->setAccessible(true);

        $messages = $method->invoke($this->chatbotService, 'tôi cần tư vấn thêm', [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'tôi tên là Huy',
                ],
            ],
        ]);

        $systemMessages = array_values(array_filter(
            $messages,
            static fn (array $message): bool => ($message['role'] ?? null) === 'system'
        ));

        $this->assertNotEmpty($systemMessages);
        $this->assertTrue(collect($systemMessages)->contains(
            fn (array $message): bool =>
                str_contains((string) ($message['content'] ?? ''), 'Huy')
                && str_contains((string) ($message['content'] ?? ''), 'gọi đúng tên')
        ));
    }

    public function test_process_message_personalizes_ai_response_with_declared_name_from_history(): void
    {
        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Dạ, anh/chị có thể mô tả rõ hơn nhu cầu để em hỗ trợ chính xác hơn.',
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('tôi cần tư vấn thêm', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'tôi tên là Huy',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Huy có thể mô tả rõ hơn', $result['response']);
        $this->assertStringNotContainsString('anh/chị', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_rewrites_identity_leakage_from_ai_provider_response(): void
    {
        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Tôi là một mô hình ngôn ngữ lớn, được Google đào tạo.',
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('Tao là tao', 1, []);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringNotContainsString('L? Huy', $result['response']);
        $this->assertStringNotContainsString('Google', $result['response']);
        $this->assertStringNotContainsString('nhà cung cấp nội bộ', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('mô hình ngôn ngữ lớn', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_with_url_only_returns_link_guidance_instead_of_symptom_advice(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage(
            'https://svh-22298.azdigihost.com:2222/evo/user/filemanager/files?path=/domains/pharmacy.phmyhu1710.dev/public_html',
            1,
            []
        );

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertStringContainsString('link', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('nên nghỉ ngơi', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_food_request_stays_out_of_domain_without_catalog_miss(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('cho tôi xin 1 bát cơm', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'mình là Huy',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Dạ, em chào Huy.',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertStringContainsString('nhà thuốc', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('chưa thấy sản phẩm khớp', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('từ khóa', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('một trợ lý ảo', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_with_nearby_pharmacy_location_request_does_not_return_categories(): void
    {
        $category = Category::factory()->create(['name' => 'Thuốc tiêu hóa']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Smecta',
            'usage' => 'Điều trị tiêu chảy.',
            'stock_quantity' => 15,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $message = 'Dạ, là một AI, em không có khả năng truy cập thông tin vị trí địa lý hoặc danh sách các tiệm thuốc cụ thể gần khu vực Đà Nẵng, quận Ngũ Hành Sơn. Anh/chị có thể thử tìm kiếm trên Google Maps hoặc các ứng dụng bản đồ khác để tìm tiệm thuốc gần nhất nhé.';
        $result = $this->chatbotService->processMessage($message, 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertStringContainsString('google maps', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('danh mục sau', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_recommend_by_symptoms_includes_disclaimer(): void
    {
        $this->aiProviderService->method('chat')->willReturn([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Bạn nên nghỉ ngơi và theo dõi thêm triệu chứng.',
                    ],
                ],
            ],
        ]);

        $result = $this->chatbotService->recommendBySymptoms('đau đầu');

        $this->assertTrue($result['show_disclaimer']);
        $this->assertArrayHasKey('disclaimer', $result);
        $this->assertNotEmpty($result['disclaimer']);
    }

    public function test_recommend_by_symptoms_returns_products_and_explanations_from_function_call(): void
    {
        $category = Category::factory()->create();
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Paracetamol 500mg',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
            'usage' => 'Giảm đau và hạ sốt',
        ]);

        $this->aiProviderService->method('chat')->willReturn([
            'choices' => [
                [
                    'message' => [
                        'function_call' => [
                            'name' => 'search_products',
                            'arguments' => json_encode(['query' => 'Paracetamol']),
                        ],
                    ],
                ],
            ],
        ]);

        $result = $this->chatbotService->recommendBySymptoms('đau đầu và sốt');

        $this->assertNotEmpty($result['products']);
        $this->assertNotEmpty($result['explanations']);
        $this->assertTrue($result['show_disclaimer']);
    }

    public function test_recommend_by_symptoms_handles_errors_gracefully(): void
    {
        $this->aiProviderService->method('chat')->willThrowException(new \Exception('API Error'));

        $result = $this->chatbotService->recommendBySymptoms('đau đầu');

        $this->assertArrayHasKey('error', $result);
        $this->assertEmpty($result['products']);
        $this->assertTrue($result['show_disclaimer']);
    }
    public function test_process_message_strips_internal_tool_narration_from_assistant_response(): void
    {
        $category = Category::factory()->create(['name' => 'Cảm cúm']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Decolgen',
            'usage' => 'Hỗ trợ giảm các triệu chứng cảm cúm, nghẹt mũi và đau đầu.',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => "Chào bạn,\n\nĐể giúp bạn tìm kiếm, tôi sẽ tìm các sản phẩm liên quan đến \"cảm cúm\".\nTôi sẽ gọi hàm `search_products` với từ khóa \"cảm cúm\".\n\nTrong lúc chờ đợi, bạn có thể tham khảo ý kiến của dược sĩ nhé.",
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('Thuốc nào tốt cho cảm cúm?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotSame('', trim($result['response']));
        $this->assertStringNotContainsString('search_products', $result['response']);
        $this->assertStringNotContainsString('gọi hàm', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('để giúp bạn tìm kiếm', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('trong lúc chờ đợi', mb_strtolower($result['response'], 'UTF-8'));
    }
}
