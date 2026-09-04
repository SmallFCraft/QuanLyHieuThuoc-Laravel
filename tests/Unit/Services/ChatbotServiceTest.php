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

    public function test_gateway_upstream_error_is_not_classified_as_auth_denied(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('classifyProviderError');
        $method->setAccessible(true);

        $upstreamError = new \RuntimeException(
            'AI provider request failed with status 502: {"error":{"message":"Upstream access forbidden, please contact administrator","type":"upstream_error"}}'
        );
        $authError = new \RuntimeException(
            'AI provider request failed with status 401: {"error":{"message":"invalid api key"}}'
        );

        $this->assertSame('provider_upstream_error', $method->invoke($this->chatbotService, $upstreamError));
        $this->assertSame('auth_or_access_denied', $method->invoke($this->chatbotService, $authError));
    }

    public function test_a_rate_limit_that_names_the_api_key_is_not_an_access_denial(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('classifyProviderError');
        $method->setAccessible(true);

        // The loose `api key` token used to win here, telling the customer their access
        // was revoked when the provider was merely out of quota.
        $rateLimited = new \RuntimeException(
            'AI provider request failed with status 429: {"error":{"message":"Your api key has exceeded its quota, retry later"}}'
        );

        $this->assertSame('capacity_or_rate_limit', $method->invoke($this->chatbotService, $rateLimited));
    }

    public function test_competitor_pharmacy_question_is_answered_locally_without_nonsense_search(): void
    {
        Product::factory()->create([
            'MaDM' => Category::factory()->create(['name' => 'Thuốc giảm đau'])->MaDM,
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage(
            'tìm thuốc nhà thuốc long châu tại đà nẵng',
            1,
            ['previous_messages' => []]
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringContainsString('Google Maps', $result['response']);
        $this->assertStringNotContainsString('long châu', mb_strtolower($result['response'], 'UTF-8'));
        // No catalog block: the old flow searched the whole sentence as a keyword.
        $this->assertStringNotContainsString('chưa thấy sản phẩm khớp hoàn toàn', $result['response']);
        $this->assertStringNotContainsString('em tìm thấy các sản phẩm sau', $result['response']);
        $this->assertEmpty($result['products']);
    }

    public function test_competitor_pharmacy_question_still_searches_the_named_medicine(): void
    {
        Product::factory()->create([
            'MaDM' => Category::factory()->create(['name' => 'Thuốc giảm đau'])->MaDM,
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
        ]);

        $result = $this->chatbotService->processMessage(
            'nhà thuốc long châu có paracetamol không?',
            1,
            ['previous_messages' => []]
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringContainsString('`paracetamol`', $result['response']);
        $this->assertNotEmpty($result['products']);
        $this->assertSame('Paracetamol 500mg', $result['products'][0]['name']);
    }

    public function test_pharmacy_location_question_points_to_maps(): void
    {
        $result = $this->chatbotService->processMessage(
            'nhà thuốc ở đà nẵng có địa chỉ nào không?',
            1,
            ['previous_messages' => []]
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Maps', $result['response']);
    }

    public function test_ambiguous_chain_name_alone_is_not_treated_as_a_competitor_question(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('isOtherPharmacyIntent');
        $method->setAccessible(true);

        // "an khang" is also an everyday phrase, so it needs a pharmacy word.
        $this->assertFalse($method->invoke($this->chatbotService, 'an khang mua ở đâu?'));
        $this->assertTrue($method->invoke($this->chatbotService, 'nhà thuốc an khang có thuốc này không?'));
        $this->assertTrue($method->invoke($this->chatbotService, 'long châu bán bao nhiêu?'));
    }

    public function test_geo_restricted_provider_error_has_its_own_label(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $classify = $reflection->getMethod('classifyProviderError');
        $classify->setAccessible(true);
        $describe = $reflection->getMethod('buildProviderUnavailableResponse');
        $describe->setAccessible(true);

        $geoError = new \RuntimeException(
            'AI provider request failed with status 400: {"error":{"message":"User location is not supported for the API use.","type":"upstream_error"}}'
        );

        $this->assertSame('provider_geo_restricted', $classify->invoke($this->chatbotService, $geoError));
        $this->assertStringContainsString('khu vực', $describe->invoke($this->chatbotService, 'provider_geo_restricted'));
    }

    public function test_provider_unavailable_response_explains_upstream_failure(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('buildProviderUnavailableResponse');
        $method->setAccessible(true);

        $response = $method->invoke($this->chatbotService, 'provider_upstream_error');

        $this->assertStringContainsString('upstream', $response);
    }

    public function test_symptom_detection_ignores_accent_free_homographs(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('isSymptomRelatedQuery');
        $method->setAccessible(true);

        $cases = [
            // "đâu", "cảm ơn", "cam" and "họ" collapse onto đau/cảm/ho once diacritics are stripped.
            ['Tôi muốn đi đà nẵng chơi thì nên đi ở đâu?', false],
            ['toi muon di da nang choi thi nen di o dau', false],
            ['Cảm ơn em nhiều nhé', false],
            ['cam on em nhieu nhe', false],
            ['Em có bán nước cam không?', false],
            ['em co ban nuoc cam khong', false],
            ['Họ nói shop bán hàng chính hãng đúng không?', false],
            ['cho anh hoi ho tro dat hang the nao', false],
            ['Bắt đầu dùng thuốc từ đầu tháng có sao không?', false],

            ['Anh bị đau đầu 2 ngày rồi', true],
            ['toi bi dau dau va sot', true],
            ['Tôi bị ho khan về đêm', true],
            ['toi bi ho khan ve dem', true],
            ['Em bị cảm cúm mấy hôm nay', true],
            ['em bi cam cum may hom nay', true],
            ['Bé nhà em bị ốm, sốt nhẹ', true],
            ['be nha em bi om sot nhe', true],
            ['Anh đau bụng dưới từ sáng', true],
        ];

        foreach ($cases as [$message, $expected]) {
            $this->assertSame(
                $expected,
                $method->invoke($this->chatbotService, $message),
                "Symptom detection mismatch for: {$message}"
            );
        }
    }

    public function test_travel_question_gets_out_of_domain_answer_instead_of_symptom_guidance(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage(
            'Tôi muốn đi đà nẵng chơi thì nên đi ở đâu?',
            1,
            ['previous_messages' => []]
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertStringContainsString('nhà thuốc', $result['response']);
        $this->assertStringNotContainsString('triệu chứng anh/chị đang mô tả', $result['response']);
        $this->assertStringNotContainsString('nghỉ ngơi, uống đủ nước', $result['response']);
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
        \Illuminate\Support\Carbon::setTestNow('2026-07-09 10:20:00');

        $this->aiProviderService->expects($this->never())->method('chat');

        try {
            $result = $this->chatbotService->processMessage("nay l\u{00E0} ng\u{00E0}y g\u{00EC}?", 1, []);
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }

        $this->assertTrue($result['success']);
        $this->assertStringContainsString("Th\u{1EE9} N\u{0103}m", $result['response']);
        $this->assertStringContainsString('09/07/2026', $result['response']);
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

    public function test_process_message_keeps_greeting_only_provider_response_without_catalog_miss(): void
    {
        $category = Category::factory()->create(['name' => 'Vitamin']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Xin Chao Ban 500mg',
            'usage' => 'San pham test khong duoc goi y cho loi chao.',
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $providerAnswer = "D\u{1EA1} em ch\u{00E0}o anh/ch\u{1ECB} \u{1EA1}. Anh/ch\u{1ECB} c\u{1EA7}n em h\u{1ED7} tr\u{1EE3} g\u{00EC} h\u{00F4}m nay?";

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
                            'content' => $providerAnswer,
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage("Xin ch\u{00E0}o b\u{1EA1}n", 1, []);

        $this->assertTrue($result['success']);
        $this->assertSame($providerAnswer, $result['response']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertEmpty($result['explanations']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertNull($result['disclaimer']);
        $this->assertSame('general', $result['response_type']);
        $this->assertTrue($result['debug']['ai_provider_used'] ?? false);
        $this->assertStringNotContainsString("ch\u{01B0}a th\u{1EA5}y s\u{1EA3}n ph\u{1EA9}m", mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString("t\u{1EEB} kh\u{00F3}a", mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('Xin Chao Ban 500mg', $result['response']);
    }

    public function test_process_message_treats_general_non_catalog_questions_as_provider_only_without_catalog_miss(): void
    {
        $category = Category::factory()->create(['name' => 'General noise']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Hello World 500mg',
            'usage' => 'San pham test khong duoc goi y cho cau hoi chung.',
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $answer = 'Hello Huy! Em co the ho tro gi cho Huy hom nay a?';

        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(static function (array $messages): bool {
                    foreach ($messages as $message) {
                        if (($message['role'] ?? null) === 'system' && str_contains((string) ($message['content'] ?? ''), 'general non-catalog question')) {
                            return true;
                        }
                    }

                    return false;
                }),
                $this->callback(static fn (array $options): bool => $options === [])
            )
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => $answer,
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('Hello world!', 1, []);

        $this->assertTrue($result['success']);
        $this->assertSame($answer, $result['response']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertEmpty($result['explanations']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertNull($result['disclaimer']);
        $this->assertStringNotContainsString("ch\u{01B0}a th\u{1EA5}y s\u{1EA3}n ph\u{1EA9}m", mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString("t\u{1EEB} kh\u{00F3}a", mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('Hello World 500mg', $result['response']);
    }

    public function test_process_message_answers_simple_arithmetic_locally_without_provider_or_latex(): void
    {
        $category = Category::factory()->create(['name' => 'Math noise']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => '1 999 500mg',
            'usage' => 'San pham test khong duoc goi y cho cau hoi tinh toan.',
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $messages = [
            '1+1/999=?' => '1 + 1 / 999 = 1.001001001001',
            "b\u{1EA1}n bi\u{1EBF}t 1 + 1 = m\u{1EA5}y ko?" => '1 + 1 = 2',
        ];

        foreach ($messages as $message => $expectedText) {
            $result = $this->chatbotService->processMessage($message, 1, []);

            $this->assertTrue($result['success'], $message);
            $this->assertStringContainsString($expectedText, $result['response'], $message);
            $this->assertEmpty($result['products'], $message);
            $this->assertEmpty($result['categories'], $message);
            $this->assertEmpty($result['explanations'], $message);
            $this->assertFalse($result['show_disclaimer'], $message);
            $this->assertNull($result['disclaimer'], $message);
            $this->assertStringNotContainsString('\\frac', $result['response'], $message);
            $this->assertStringNotContainsString('\\approx', $result['response'], $message);
            $this->assertStringNotContainsString('\\ldots', $result['response'], $message);
            $this->assertStringNotContainsString('\\(', $result['response'], $message);
            $this->assertStringNotContainsString('\\)', $result['response'], $message);
            $this->assertStringNotContainsString('1 999 500mg', $result['response'], $message);
        }
    }

    public function test_process_message_blocks_unsupported_image_and_file_generation_without_provider(): void
    {
        $category = Category::factory()->create(['name' => 'Creative noise']);
        foreach (['Tao Anh 500mg', 'Tao File Pdf 500mg'] as $name) {
            Product::factory()->create([
                'MaDM' => $category->MaDM,
                'name' => $name,
                'usage' => 'San pham test khong duoc goi y cho yeu cau tao artifact.',
                'stock_quantity' => 200,
                'expiration_date' => now()->addYear(),
            ]);
        }

        $this->aiProviderService->expects($this->never())->method('chat');

        $cases = [
            "t\u{1EA1}o \u{1EA3}nh con m\u{00E8}o" => "ch\u{01B0}a h\u{1ED7} tr\u{1EE3} t\u{1EA1}o \u{1EA3}nh",
            "t\u{1EA1}o file pdf cho t\u{00F4}i" => "ch\u{01B0}a h\u{1ED7} tr\u{1EE3} t\u{1EA1}o file",
        ];

        foreach ($cases as $message => $expectedText) {
            $result = $this->chatbotService->processMessage($message, 1, []);
            $lowerResponse = mb_strtolower($result['response'], 'UTF-8');

            $this->assertTrue($result['success'], $message);
            $this->assertStringContainsString($expectedText, $lowerResponse, $message);
            $this->assertEmpty($result['products'], $message);
            $this->assertEmpty($result['categories'], $message);
            $this->assertEmpty($result['explanations'], $message);
            $this->assertFalse($result['show_disclaimer'], $message);
            $this->assertNull($result['disclaimer'], $message);
            $this->assertStringNotContainsString("ch\u{01B0}a th\u{1EA5}y s\u{1EA3}n ph\u{1EA9}m", $lowerResponse, $message);
            $this->assertStringNotContainsString("t\u{1EEB} kh\u{00F3}a", $lowerResponse, $message);
            $this->assertStringNotContainsString('Tao Anh 500mg', $result['response'], $message);
            $this->assertStringNotContainsString('Tao File Pdf 500mg', $result['response'], $message);
        }
    }

    public function test_process_message_sanitizes_provider_latex_math_for_plain_text_chat(): void
    {
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
                            'content' => 'Huy, \\(1 + \\frac{1}{999} = \\frac{1000}{999} \\approx 1.001001001\\ldots\\)',
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('Hello world!', 1, []);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('1 + 1/999 = 1000/999 ~ 1.001001001...', $result['response']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertEmpty($result['explanations']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertNull($result['disclaimer']);
        $this->assertStringNotContainsString('\\frac', $result['response']);
        $this->assertStringNotContainsString('\\approx', $result['response']);
        $this->assertStringNotContainsString('\\ldots', $result['response']);
        $this->assertStringNotContainsString('\\(', $result['response']);
        $this->assertStringNotContainsString('\\)', $result['response']);
    }

    public function test_process_message_answers_bot_name_question_locally_without_catalog_fallback(): void
    {
        $category = Category::factory()->create(['name' => 'Identity noise']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Ban Ten La Gi 500mg',
            'usage' => 'San pham test khong duoc goi y cho cau hoi identity.',
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("B\u{1EA1}n t\u{00EA}n l\u{00E0} g\u{00EC}?", 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => "t\u{00F4}i t\u{00EA}n l\u{00E0} B\u{00F2} Heo",
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertEmpty($result['explanations']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertNull($result['disclaimer']);
        $this->assertSame('general', $result['response_type']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringNotContainsString("ch\u{01B0}a th\u{1EA5}y s\u{1EA3}n ph\u{1EA9}m", mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString("t\u{1EEB} kh\u{00F3}a", mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('Ban Ten La Gi 500mg', $result['response']);
    }

    public function test_process_message_answers_current_chat_session_id_locally_without_provider(): void
    {
        $category = Category::factory()->create(['name' => 'Session noise']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Phien Chat Ma Id 500mg',
            'usage' => 'San pham test khong duoc goi y cho cau hoi session id.',
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $sessionId = '701c1cdf-61b8-473a-9ecd-99ebd80b9e94';

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("\u{0111}\u{00E2}y l\u{00E0} phi\u{00EA}n chat m\u{00E3} id l\u{00E0} g\u{00EC}?", 1, [
            'current_session_id' => $sessionId,
        ]);

        $lowerResponse = mb_strtolower($result['response'], 'UTF-8');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('CHAT-701C1CDF', $result['response']);
        $this->assertStringContainsString($sessionId, $result['response']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertEmpty($result['explanations']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertNull($result['disclaimer']);
        $this->assertSame('general', $result['response_type']);
        $this->assertStringNotContainsString("kh\u{00F4}ng c\u{00F3} quy\u{1EC1}n", $lowerResponse);
        $this->assertStringNotContainsString("ch\u{01B0}a th\u{1EA5}y s\u{1EA3}n ph\u{1EA9}m", $lowerResponse);
        $this->assertStringNotContainsString('Phien Chat Ma Id 500mg', $result['response']);
    }

    public function test_process_message_treats_social_chitchat_as_non_catalog_without_symptom_or_disclaimer(): void
    {
        $category = Category::factory()->create(['name' => 'Social noise']);
        foreach (['Cam On Ban 500mg', 'Shop Oi 250mg', 'Test Thoi 100mg'] as $name) {
            Product::factory()->create([
                'MaDM' => $category->MaDM,
                'name' => $name,
                'usage' => 'San pham test khong duoc goi y cho social chitchat.',
                'stock_quantity' => 200,
                'expiration_date' => now()->addYear(),
            ]);
        }

        $messages = [
            "c\u{1EA3}m \u{01A1}n b\u{1EA1}n",
            "\u{1EEB} c\u{1EA3}m \u{01A1}n",
            'thanks',
            "ok b\u{1EA1}n",
            "c\u{00F3} ai kh\u{00F4}ng",
            "\u{1ED5}n ko",
            "\u{1ED5}n k",
            "shop \u{01A1}i",
            "bot \u{01A1}i",
            "t\u{1EA1}m bi\u{1EC7}t",
            'bye',
            "h\u{1ECF}i ch\u{00FA}t",
            "test th\u{00F4}i",
        ];
        $providerAnswer = "D\u{1EA1}, em \u{0111}\u{00E2}y \u{1EA1}.";

        $this->aiProviderService->expects($this->exactly(count($messages)))
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
                            'content' => $providerAnswer,
                        ],
                    ],
                ],
            ]);

        foreach ($messages as $message) {
            $result = $this->chatbotService->processMessage($message, 1, []);

            $this->assertTrue($result['success'], $message);
            $this->assertSame($providerAnswer, $result['response'], $message);
            $this->assertEmpty($result['products'], $message);
            $this->assertEmpty($result['categories'], $message);
            $this->assertEmpty($result['explanations'], $message);
            $this->assertFalse($result['show_disclaimer'], $message);
            $this->assertNull($result['disclaimer'], $message);
            $this->assertStringNotContainsString("tri\u{1EC7}u ch\u{1EE9}ng", mb_strtolower($result['response'], 'UTF-8'), $message);
            $this->assertStringNotContainsString("ch\u{01B0}a th\u{1EA5}y s\u{1EA3}n ph\u{1EA9}m", mb_strtolower($result['response'], 'UTF-8'), $message);
            $this->assertStringNotContainsString("t\u{1EEB} kh\u{00F3}a", mb_strtolower($result['response'], 'UTF-8'), $message);
        }
    }

    public function test_process_message_replaces_off_topic_provider_reply_for_social_chitchat(): void
    {
        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(static function (array $messages): bool {
                    foreach ($messages as $message) {
                        if (($message['role'] ?? null) === 'system' && str_contains((string) ($message['content'] ?? ''), 'social-only chitchat')) {
                            return true;
                        }
                    }

                    return false;
                }),
                $this->callback(static fn (array $options): bool => $options === [])
            )
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => "D\u{1EA1} em nghe anh/ch\u{1ECB} \u{1EA1}. Anh/ch\u{1ECB} c\u{1EA7}n t\u{00EC}m thu\u{1ED1}c/s\u{1EA3}n ph\u{1EA9}m g\u{00EC}, ho\u{1EB7}c \u{0111}ang c\u{00F3} tri\u{1EC7}u ch\u{1EE9}ng n\u{00E0}o c\u{1EA7}n em t\u{01B0} v\u{1EA5}n kh\u{00F4}ng?",
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('alo shop', 1, []);

        $this->assertTrue($result['success']);
        $this->assertSame("D\u{1EA1}, em ch\u{00E0}o anh/ch\u{1ECB} \u{1EA1}.", $result['response']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertNull($result['disclaimer']);
    }

    public function test_process_message_replaces_off_topic_provider_reply_for_on_ko_chitchat(): void
    {
        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(static function (array $messages): bool {
                    foreach ($messages as $message) {
                        if (($message['role'] ?? null) === 'system' && str_contains((string) ($message['content'] ?? ''), 'social-only chitchat')) {
                            return true;
                        }
                    }

                    return false;
                }),
                $this->callback(static fn (array $options): bool => $options === [])
            )
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => "D\u{1EA1} \u{1ED5}n anh/ch\u{1ECB} nh\u{00E9}. Anh/ch\u{1ECB} c\u{1EA7}n em t\u{00EC}m thu\u{1ED1}c hay t\u{01B0} v\u{1EA5}n s\u{1EA3}n ph\u{1EA9}m g\u{00EC} kh\u{00F4}ng?",
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage("\u{1ED5}n ko", 1, []);

        $this->assertTrue($result['success']);
        $this->assertSame("D\u{1EA1}, em v\u{1EAB}n s\u{1EB5}n s\u{00E0}ng h\u{1ED7} tr\u{1EE3} \u{1EA1}.", $result['response']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertFalse($result['show_disclaimer']);
        $this->assertNull($result['disclaimer']);
    }

    public function test_process_message_preserves_cold_symptom_guidance_for_real_health_intent(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("t\u{00F4}i b\u{1ECB} c\u{1EA3}m", 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertSame('health_consultation', $result['response_type']);
        $this->assertStringContainsString("tri\u{1EC7}u ch\u{1EE9}ng", mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_preserves_cold_medicine_product_lookup_for_real_product_intent(): void
    {
        $category = Category::factory()->create(['name' => "C\u{1EA3}m c\u{00FA}m"]);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Coldcare',
            'usage' => "H\u{1ED7} tr\u{1EE3} gi\u{1EA3}m tri\u{1EC7}u ch\u{1EE9}ng c\u{1EA3}m c\u{00FA}m.",
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $options): bool => !empty($options['functions']) && ($options['function_call'] ?? null) === 'auto')
            )
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'function_call' => [
                                'name' => 'search_products',
                                'arguments' => json_encode([
                                    'query' => 'Coldcare',
                                    'symptoms' => ["c\u{1EA3}m"],
                                ]),
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage("c\u{00F3} thu\u{1ED1}c c\u{1EA3}m kh\u{00F4}ng", 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertContains('Coldcare', array_column($result['products'], 'name'));
        $this->assertTrue($result['show_disclaimer']);
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

    public function test_process_message_handles_name_intro_with_product_lookup_intent(): void
    {
        $category = Category::factory()->create(['name' => 'Vitamin']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin C 1000mg',
            'ingredients' => 'Acid ascorbic 1000mg',
            'usage' => 'Công dụng: Bổ sung vitamin C, tăng sức đề kháng. Liều lượng: uống 1 viên/ngày sau ăn.',
            'stock_quantity' => 120,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage(
            'mình tên là Huy, mình muốn tìm Vitamin C. hãy cho mình biết công dụng và liều lượng nhé.',
            1,
            []
        );

        $this->assertTrue($result['success']);
        $this->assertSame('product_search', $result['response_type']);
        $this->assertNotEmpty($result['products']);
        $this->assertContains('Vitamin C 1000mg', array_column($result['products'], 'name'));
        $this->assertStringContainsString('Vitamin C 1000mg', $result['response']);
        $this->assertStringContainsString('Công dụng', $result['response']);
        $this->assertStringContainsString('Liều lượng', $result['response']);
        $this->assertStringNotContainsString('Huy Muốn', $result['response']);
        $this->assertStringNotContainsString('Muốn Tìm Vitamin', $result['response']);
        $this->assertStringNotContainsString('Em có thể hỗ trợ Huy', $result['response']);
    }

    public function test_process_message_trims_name_from_mixed_intro_history(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('bạn có thể làm gì?', 1, [
            'previous_messages' => [
                [
                    'role' => 'user',
                    'content' => 'mình tên là Huy, mình muốn tìm Vitamin C. hãy cho mình biết công dụng và liều lượng nhé.',
                ],
                [
                    'role' => 'assistant',
                    'content' => 'Dạ, với từ khóa `vitamin c`, em tìm thấy sản phẩm Vitamin C 1000mg.',
                ],
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Huy', $result['response']);
        $this->assertStringNotContainsString('Huy Muốn', $result['response']);
        $this->assertStringNotContainsString('Muốn Tìm Vitamin', $result['response']);
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

    public function test_catalog_lookup_falls_back_to_products_in_matched_category(): void
    {
        $category = Category::factory()->create([
            'name' => 'Thuốc tiêu hóa',
            'description' => 'Thuốc điều trị các bệnh về đường tiêu hóa',
        ]);

        $catalog = [
            ['name' => 'Omeprazole 20mg', 'usage' => 'Điều trị loét dạ dày, trào ngược.'],
            ['name' => 'Probiotics', 'usage' => 'Bổ sung men vi sinh đường ruột.'],
            ['name' => 'Simethicone 80mg', 'usage' => 'Giảm đầy hơi, chướng bụng.'],
        ];

        foreach ($catalog as $item) {
            Product::factory()->create([
                'MaDM' => $category->MaDM,
                'name' => $item['name'],
                'usage' => $item['usage'],
                'stock_quantity' => 50,
                'expiration_date' => now()->addYear(),
            ]);
        }

        // The matched category answers the question from stock, so no provider round trip.
        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('có thuốc gì hỗ trơ tiêu hóa nhanh không?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertNotEmpty($result['categories']);
        $this->assertSame('Thuốc tiêu hóa', $result['categories'][0]['name']);
        $this->assertStringContainsString('Thuốc tiêu hóa', $result['response']);
        $this->assertStringContainsString('Omeprazole 20mg', $result['response']);
        $this->assertStringNotContainsString('chưa thấy sản phẩm', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('chưa tìm thấy sản phẩm', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_catalog_lookup_does_not_suggest_category_products_without_a_query(): void
    {
        $category = Category::factory()->create(['name' => 'Thuốc tiêu hóa']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Probiotics',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('buildCatalogLookupResponse');
        $method->setAccessible(true);

        $result = $method->invoke($this->chatbotService, '', 'abc', false, []);

        $this->assertEmpty($result['products']);
    }

    public function test_catalog_lookup_matches_category_from_multi_word_functional_query(): void
    {
        $digestive = Category::factory()->create([
            'name' => 'Thuốc tiêu hóa',
            'description' => 'Thuốc điều trị các bệnh về đường tiêu hóa',
        ]);
        Product::factory()->create([
            'MaDM' => $digestive->MaDM,
            'name' => 'Simethicone 80mg',
            'usage' => 'Giảm đầy hơi, chướng bụng.',
            'stock_quantity' => 40,
            'expiration_date' => now()->addYear(),
        ]);

        $painkillers = Category::factory()->create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Các loại thuốc giảm đau, hạ sốt',
        ]);
        Product::factory()->create([
            'MaDM' => $painkillers->MaDM,
            'name' => 'Paracetamol 500mg',
            'stock_quantity' => 90,
            'expiration_date' => now()->addYear(),
        ]);

        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('buildCatalogLookupResponse');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->chatbotService,
            'hỗ trợ tiêu hóa nhanh',
            'có thuốc gì hỗ trơ tiêu hóa nhanh không?',
            false,
            []
        );

        $this->assertCount(1, $result['categories']);
        $this->assertSame('Thuốc tiêu hóa', $result['categories'][0]['name']);
        $this->assertNotEmpty($result['products']);
        $this->assertSame('Simethicone 80mg', $result['products'][0]['name']);
        $this->assertStringNotContainsString('chưa thấy sản phẩm', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_catalog_lookup_ignores_generic_words_when_resolving_a_category(): void
    {
        foreach (['Thuốc tiêu hóa', 'Thuốc giảm đau - Hạ sốt', 'Thuốc tim mạch'] as $name) {
            $category = Category::factory()->create(['name' => $name, 'description' => $name]);
            Product::factory()->create([
                'MaDM' => $category->MaDM,
                'name' => 'San pham ' . $category->MaDM,
                'stock_quantity' => 20,
                'expiration_date' => now()->addYear(),
            ]);
        }

        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('buildCatalogLookupResponse');
        $method->setAccessible(true);

        $result = $method->invoke($this->chatbotService, 'thuốc gì cũng được', 'thuốc gì cũng được', false, []);

        $this->assertEmpty($result['products']);
    }

    public function test_ai_history_drops_provider_failure_turns_and_collapses_retries(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('sanitizeHistoryForAi');
        $method->setAccessible(true);

        $failureMetadata = [
            'debug' => [
                'fallback_type' => 'provider_unavailable_no_catalog_match',
                'provider_error_type' => 'network_or_timeout',
            ],
        ];

        $history = $method->invoke($this->chatbotService, [
            ['role' => 'user', 'content' => 'có nên xài crack hay không'],
            ['role' => 'assistant', 'content' => 'Dạ, hiện tại kết nối tới nhà cung cấp AI đang chậm hoặc timeout.', 'metadata' => $failureMetadata],
            ['role' => 'user', 'content' => 'có nên xài crack hay không'],
            ['role' => 'assistant', 'content' => 'Dạ, hiện tại kết nối tới nhà cung cấp AI đang chậm hoặc timeout.', 'metadata' => $failureMetadata],
            ['role' => 'user', 'content' => 'có nên xài crack hay không'],
            ['role' => 'assistant', 'content' => 'Dạ, hiện tại kết nối tới nhà cung cấp AI đang chậm hoặc timeout.', 'metadata' => $failureMetadata],
            ['role' => 'user', 'content' => 'có nên xài crack hay không'],
            ['role' => 'assistant', 'content' => 'Dạ, hiện tại em chưa thấy sản phẩm khớp hoàn toàn với từ khóa `crack`.'],
            ['role' => 'user', 'content' => 'có thuốc gì hỗ trơ tiêu hóa nhanh không?'],
            ['role' => 'assistant', 'content' => 'Dạ, trong danh mục Thuốc tiêu hóa em thấy các sản phẩm sau.'],
        ]);

        $this->assertCount(4, $history);

        $crackTurns = array_values(array_filter(
            $history,
            static fn (array $message): bool => str_contains((string) $message['content'], 'xài crack')
        ));

        $this->assertCount(1, $crackTurns);

        foreach ($history as $message) {
            $this->assertStringNotContainsString('nhà cung cấp AI', (string) $message['content']);
        }

        $previousRole = null;
        foreach ($history as $message) {
            $this->assertNotSame($previousRole, $message['role']);
            $previousRole = $message['role'];
        }

        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('assistant', $history[3]['role']);
    }

    public function test_ai_history_anchors_elliptical_follow_up_to_latest_topic(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('buildConversationMessages');
        $method->setAccessible(true);

        $messages = $method->invoke($this->chatbotService, 'có ổn không?', [
            'previous_messages' => [
                ['role' => 'user', 'content' => 'có nên xài crack hay không'],
                ['role' => 'assistant', 'content' => 'Dạ, em không thể tư vấn về việc sử dụng ma túy.'],
                ['role' => 'user', 'content' => 'có thuốc gì hỗ trơ tiêu hóa nhanh không?'],
                ['role' => 'assistant', 'content' => 'Dạ, trong danh mục Thuốc tiêu hóa em thấy các sản phẩm sau.'],
            ],
        ]);

        $anchor = $messages[count($messages) - 2] ?? [];
        $current = $messages[count($messages) - 1] ?? [];

        $this->assertSame('system', $anchor['role'] ?? null);
        $this->assertStringContainsString('tiêu hóa', (string) ($anchor['content'] ?? ''));
        $this->assertStringContainsString('rút gọn', (string) ($anchor['content'] ?? ''));
        $this->assertStringNotContainsString('crack', (string) ($anchor['content'] ?? ''));

        // The resolved reference also rides on the user turn, which the provider
        // weighs more heavily than a system line.
        $this->assertSame('user', $current['role'] ?? null);
        $this->assertStringStartsWith('có ổn không?', (string) ($current['content'] ?? ''));
        $this->assertStringContainsString('đang nói về: có thuốc gì hỗ trơ tiêu hóa nhanh không?', (string) ($current['content'] ?? ''));

        // The older topic is withheld entirely, so it cannot be answered instead.
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('crack', (string) ($message['content'] ?? ''));
        }

        $history = array_values(array_filter(
            $messages,
            static fn (array $message): bool => in_array($message['role'] ?? null, ['user', 'assistant'], true)
        ));

        $this->assertCount(3, $history);
        $this->assertSame('có thuốc gì hỗ trơ tiêu hóa nhanh không?', $history[0]['content']);
        $this->assertSame('assistant', $history[1]['role']);
    }

    public function test_ai_history_does_not_anchor_a_message_that_has_its_own_topic(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('buildConversationMessages');
        $method->setAccessible(true);

        $messages = $method->invoke($this->chatbotService, 'giá thuốc Paracetamol bao nhiêu?', [
            'previous_messages' => [
                ['role' => 'user', 'content' => 'có thuốc gì hỗ trơ tiêu hóa nhanh không?'],
                ['role' => 'assistant', 'content' => 'Dạ, trong danh mục Thuốc tiêu hóa em thấy các sản phẩm sau.'],
            ],
        ]);

        $current = $messages[count($messages) - 1] ?? [];

        $this->assertSame('user', $current['role'] ?? null);
        $this->assertSame('giá thuốc Paracetamol bao nhiêu?', $current['content'] ?? null);
        $this->assertSame('assistant', $messages[count($messages) - 2]['role'] ?? null);
    }

    public function test_illicit_substance_question_returns_safety_guidance_without_catalog_search(): void
    {
        $category = Category::factory()->create(['name' => 'Thuốc giảm đau']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Paracetamol 500mg',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $this->aiProviderService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('có nên xài crack hay không', 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertStringContainsString('115', $result['response']);
        $this->assertStringContainsString('cơ sở y tế', $result['response']);
        $this->assertStringNotContainsString('từ khóa', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('chưa thấy sản phẩm', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_everyday_words_are_not_treated_as_illicit_substance_questions(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('isIllicitSubstanceIntent');
        $method->setAccessible(true);

        foreach ([
            'có nước đá không',
            'em bị đá bóng vào chân',
            'thuốc cỏ mực có không',
            'cho hỏi giá thuốc ke',
            'có thuốc gì hỗ trợ tiêu hóa nhanh không?',
        ] as $message) {
            $this->assertFalse(
                $method->invoke($this->chatbotService, $message),
                "False positive on: {$message}"
            );
        }

        foreach ([
            'có nên xài crack hay không',
            'bán ma túy không',
            'tôi muốn mua cần sa',
            'heroin giá bao nhiêu',
        ] as $message) {
            $this->assertTrue(
                $method->invoke($this->chatbotService, $message),
                "Missed detection on: {$message}"
            );
        }
    }

    public function test_assistant_response_drops_a_disclaimer_repeated_inside_the_text(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('sanitizeAssistantResponse');
        $method->setAccessible(true);

        $cases = [
            "Hệ thống có **Simethicone 80mg** – 4.000đ/viên, giúp giảm đầy hơi.\n\n> ⚠️ Tham khảo ý kiến dược sĩ/bác sĩ trước khi dùng.",
            'Hệ thống có **Simethicone 80mg** – 4.000đ/viên, giúp giảm đầy hơi. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.',
            "Hệ thống có **Simethicone 80mg** – 4.000đ/viên, giúp giảm đầy hơi.\nNội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.",
            "Hệ thống có **Simethicone 80mg** – 4.000đ/viên, giúp giảm đầy hơi.\nAnh/chị vui lòng đọc kỹ hướng dẫn sử dụng và tham khảo thêm ý kiến dược sĩ hoặc bác sĩ.",
        ];

        foreach ($cases as $case) {
            $cleaned = $method->invoke($this->chatbotService, $case);

            $this->assertStringContainsString('Simethicone 80mg', $cleaned, $case);
            $this->assertStringNotContainsString('dược sĩ', $cleaned, $case);
            $this->assertStringNotContainsString('bác sĩ', $cleaned, $case);
            $this->assertStringNotContainsString('do AI tạo ra', $cleaned, $case);
        }
    }

    public function test_assistant_response_keeps_pharmacist_advice_that_carries_content(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('sanitizeAssistantResponse');
        $method->setAccessible(true);

        $kept = [
            'Dạ, các loại vitamin thường an toàn khi dùng đúng liều. Nếu anh/chị có bệnh nền, đang dùng thuốc khác hoặc muốn dùng liều cao thì nên hỏi dược sĩ/bác sĩ trước.',
            'Dạ, em kiểm tra Paracetamol và thấy các lưu ý sau ạ. Trong đó có cảnh báo ở mức nghiêm trọng, anh/chị nên hỏi dược sĩ trước khi dùng thuốc này ạ.',
            'Dạ, với các dấu hiệu này anh/chị nên đến gặp bác sĩ trong hôm nay để được khám trực tiếp.',
        ];

        foreach ($kept as $text) {
            $this->assertSame($text, $method->invoke($this->chatbotService, $text));
        }
    }

    public function test_product_search_narrows_a_multi_word_symptom_query(): void
    {
        $digestive = Category::factory()->create(['name' => 'Thuốc tiêu hóa']);
        $painkillers = Category::factory()->create(['name' => 'Thuốc giảm đau - Hạ sốt']);

        Product::factory()->create([
            'MaDM' => $digestive->MaDM,
            'name' => 'Omeprazole 20mg',
            'usage' => 'Điều trị loét dạ dày, trào ngược. Uống 1 viên/ngày trước ăn',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
        ]);
        Product::factory()->create([
            'MaDM' => $digestive->MaDM,
            'name' => 'Esomeprazole 40mg',
            'usage' => 'Điều trị trào ngược dạ dày. Uống 1 viên/ngày',
            'stock_quantity' => 80,
            'expiration_date' => now()->addYear(),
        ]);
        Product::factory()->create([
            'MaDM' => $painkillers->MaDM,
            'name' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt',
            'stock_quantity' => 90,
            'expiration_date' => now()->addYear(),
        ]);

        $names = $this->chatbotService->searchProducts('đau dạ dày')->pluck('name')->all();

        $this->assertNotEmpty($names);
        $this->assertContains('Omeprazole 20mg', $names);
        $this->assertContains('Esomeprazole 40mg', $names);
        $this->assertNotContains('Paracetamol 500mg', $names);
    }

    public function test_product_search_resolves_a_stomach_query_before_a_painkiller_one(): void
    {
        $digestive = Category::factory()->create(['name' => 'Thuốc tiêu hóa']);
        $painkillers = Category::factory()->create(['name' => 'Thuốc giảm đau - Hạ sốt']);

        Product::factory()->create([
            'MaDM' => $digestive->MaDM,
            'name' => 'Omeprazole 20mg',
            'usage' => 'Điều trị loét dạ dày, trào ngược',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
        ]);
        Product::factory()->create([
            'MaDM' => $painkillers->MaDM,
            'name' => 'Diclofenac 50mg',
            'usage' => 'Giảm đau, kháng viêm',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
        ]);

        // NSAIDs make a stomach complaint worse, so "dạ dày" has to win over "giảm đau".
        $names = $this->chatbotService->searchProducts('giảm đau dạ dày')->pluck('name')->all();

        $this->assertContains('Omeprazole 20mg', $names);
        $this->assertNotContains('Diclofenac 50mg', $names);
    }

    public function test_product_search_does_not_answer_from_a_single_stray_token(): void
    {
        $vitamins = Category::factory()->create(['name' => 'Vitamin và khoáng chất']);
        Product::factory()->create([
            'MaDM' => $vitamins->MaDM,
            'name' => 'Vitamin E 400IU',
            'usage' => 'Chống oxy hóa, bảo vệ tế bào',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
        ]);

        // "tiêu hóa" narrowed to "hóa" used to match "chống oxy hóa". A miss here is
        // correct: the category lookup answers this query from the digestive shelf.
        $this->assertEmpty($this->chatbotService->searchProducts('tiêu hóa')->all());
        $this->assertEmpty($this->chatbotService->searchProducts('hỗ trợ tiêu hóa nhanh')->all());
    }

    public function test_product_search_ignores_an_accent_blind_substring_hit(): void
    {
        $vitamins = Category::factory()->create(['name' => 'Thuốc bổ & vitamin']);
        Product::factory()->create([
            'MaDM' => $vitamins->MaDM,
            'name' => 'Siro A.T Ascorbic',
            'ingredients' => 'Acid ascorbic 500mg',
            // MySQL's utf8mb4_unicode_ci ignores Vietnamese diacritics, so
            // `LIKE '%thử thai%'` matched this pharmacology text and answered a
            // pregnancy-test question with a vitamin C syrup.
            'usage' => 'Có một ngưỡng đào thải acid ascorbic qua thận khoảng 14 microgam/ml',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $this->assertEmpty($this->chatbotService->searchProducts('que thử thai')->all());
        $this->assertEmpty($this->chatbotService->searchProducts('thử thai')->all());
    }

    public function test_product_search_drops_a_price_question_before_narrowing(): void
    {
        $digestive = Category::factory()->create(['name' => 'Thuốc tiêu hóa']);
        Product::factory()->create([
            'MaDM' => $digestive->MaDM,
            'name' => 'Biviantac',
            'usage' => 'Không rõ cần trung hòa lượng acid dịch vị bao nhiêu là tối ưu',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);
        $wanted = Product::factory()->create([
            'MaDM' => $digestive->MaDM,
            'name' => 'Men vi sinh Enterogermina',
            'usage' => 'Bổ sung men vi sinh, hỗ trợ tiêu hóa',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $names = $this->chatbotService->searchProducts('men vi sinh giá bao nhiêu')->pluck('name')->all();

        $this->assertContains($wanted->name, $names);
        $this->assertNotContains('Biviantac', $names);
    }

    public function test_match_verification_keeps_an_unaccented_query(): void
    {
        $method = new \ReflectionMethod($this->chatbotService, 'phraseOccursAsWord');
        $method->setAccessible(true);

        // The verification step runs after an accent-blind LIKE prefilter, so on MySQL
        // it must not undo the thing that prefilter is good at: a customer typing
        // without diacritics still reaches accented product text.
        $this->assertTrue($method->invoke($this->chatbotService, 'Điều trị loét dạ dày', 'da day'));
        $this->assertTrue($method->invoke($this->chatbotService, 'Điều trị loét dạ dày', 'dạ dày'));

        // Typed with diacritics, they have to survive: "thử thai" is not "thu thải".
        $this->assertFalse($method->invoke($this->chatbotService, 'ngưỡng đào thải acid ascorbic', 'thử thai'));

        // Whole words only, either way.
        $this->assertFalse($method->invoke($this->chatbotService, 'chống oxy hóa', 'hoa qua'));
    }

    public function test_product_search_ignores_the_verb_a_customer_wraps_the_query_in(): void
    {
        $respiratory = Category::factory()->create(['name' => 'Thuốc ho - hô hấp']);
        $wanted = Product::factory()->create([
            'MaDM' => $respiratory->MaDM,
            'name' => 'Siro Thuốc ho Bách Hộ',
            'usage' => 'Điều trị ho dai dẳng lâu ngày, ho gà',
            'stock_quantity' => 60,
            'expiration_date' => now()->addYear(),
        ]);

        // Every keyword tier LIKE-matches the surviving text as one phrase, so
        // "kiếm thuốc ho" searched for the literal string "kiếm thuốc ho" and found
        // nothing while the shelf held five cough medicines.
        foreach (['kiếm thuốc ho', 'kiem thuoc ho', 'coi thuốc ho', 'tư vấn thuốc ho'] as $phrasing) {
            $method = new \ReflectionMethod($this->chatbotService, 'sanitizeCatalogQuery');
            $method->setAccessible(true);
            $names = $this->chatbotService
                ->searchProducts($method->invoke($this->chatbotService, $phrasing))
                ->pluck('name')
                ->all();

            $this->assertContains($wanted->name, $names, "\"{$phrasing}\" found nothing");
        }
    }

    public function test_assistant_response_removes_foreign_script_words(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('sanitizeAssistantResponse');
        $method->setAccessible(true);

        $cleaned = $method->invoke(
            $this->chatbotService,
            'Anh/chị thử từ khóa cụ thể như: "omeprazole", "tha酸", "bảo vệ niêm mạc dạ dày" nhé.'
        );

        $this->assertStringNotContainsString('酸', $cleaned);
        $this->assertStringNotContainsString('""', $cleaned);
        $this->assertStringNotContainsString(', ,', $cleaned);
        $this->assertStringContainsString('"omeprazole"', $cleaned);
        $this->assertStringContainsString('"bảo vệ niêm mạc dạ dày"', $cleaned);

        $this->assertSame(
            'Thần kinh: co giật, tổn thương não vĩnh viễn.',
            $method->invoke($this->chatbotService, 'Thần kinh: co giật, 중풍, tổn thương não vĩnh viễn.')
        );

        // Latin text with Vietnamese diacritics and units must survive untouched.
        $intact = 'Uống 1 viên × 3 lần/ngày sau ăn – giá 4.000đ (µg/mL).';
        $this->assertSame($intact, $method->invoke($this->chatbotService, $intact));
    }

    public function test_ai_answer_is_not_followed_by_a_no_match_catalog_block(): void
    {
        $this->aiProviderService->expects($this->once())
            ->method('chat')
            ->willReturn([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Em nói là tìm kiếm từ khóa chung không trả về kết quả, chứ không phải hệ thống không có thuốc.',
                        ],
                    ],
                ],
            ]);

        $result = $this->chatbotService->processMessage('tôi bị đau dạ dày, có thuốc gì không?', 1, []);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Em nói là tìm kiếm từ khóa chung', $result['response']);
        $this->assertStringNotContainsString('chưa thấy sản phẩm', mb_strtolower($result['response'], 'UTF-8'));
        $this->assertStringNotContainsString('từ khóa `', $result['response']);
    }

    public function test_response_intro_does_not_echo_a_noisy_query(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('buildCategoryResponseText');
        $method->setAccessible(true);

        $categories = [[
            'id' => 4,
            'name' => 'Vitamin và khoáng chất',
            'description' => 'Thực phẩm chức năng',
            'product_count' => 11,
            'url' => 'http://localhost/products?category=4',
        ]];

        $noisy = $method->invoke($this->chatbotService, $categories, [], 'hiên tai ở website trang danh muc không');
        $this->assertStringNotContainsString('hiên tai', $noisy);
        $this->assertStringNotContainsString('danh muc không', $noisy);
        $this->assertStringContainsString('Vitamin và khoáng chất', $noisy);

        // A real keyword is still worth showing back.
        $clean = $method->invoke($this->chatbotService, $categories, [], 'vitamin');
        $this->assertStringContainsString('`vitamin`', $clean);
    }

    public function test_grounded_response_drops_links_duplicating_a_category_card(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('stripDuplicateCategoryCardLinks');
        $method->setAccessible(true);

        $categoryUrl = 'http://localhost:8000/products?category=5';
        $categories = [['id' => 5, 'name' => 'Thuốc tiêu hóa', 'url' => $categoryUrl]];

        $text = "Danh mục **Thuốc tiêu hóa** đang có 10 sản phẩm:\n\n"
            . "[Xem chi tiết danh mục Thuốc tiêu hóa]({$categoryUrl})\n\n"
            . "Anh/chị có thể xem [Thuốc tiêu hóa]({$categoryUrl}) để lọc nhanh hơn.";

        $cleaned = $method->invoke($this->chatbotService, $text, $categories);

        $this->assertStringNotContainsString("]({$categoryUrl})", $cleaned);
        $this->assertStringNotContainsString('Xem chi tiết danh mục', $cleaned);
        $this->assertStringContainsString('Danh mục **Thuốc tiêu hóa** đang có 10 sản phẩm', $cleaned);
        $this->assertStringContainsString('có thể xem Thuốc tiêu hóa để lọc nhanh hơn', $cleaned);
    }

    /**
     * The repair has to fire on a URL the model wrapped in plain parens — the shape that
     * shipped raw to the widget — without double-linking one that is already markdown.
     * The label must be the exact string the widget promotes to a button.
     */
    public function test_raw_product_urls_become_the_product_button_in_every_written_shape(): void
    {
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('formatFriendlyProductLinks');
        $method->setAccessible(true);

        $url = 'http://localhost/products/15';
        $products = [['name' => 'Ciprofloxacin 500mg', 'url' => $url]];
        $button = "[Mở sản phẩm]({$url})";

        $cases = [
            // Observed in a real session: the model printed the URL in plain parens.
            "còn 200 ({$url})." => "còn 200 {$button}.",
            "Xem tại {$url} nhé." => "Xem tại {$button} nhé.",
            // Already correct - must not be wrapped twice.
            "- Xem chi tiết: {$button}" => "- Xem chi tiết: {$button}",
            // Another label already links it; leave the author's wording alone.
            "- [Chi tiết]({$url})" => "- [Chi tiết]({$url})",
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame(
                $expected,
                $method->invoke($this->chatbotService, $input, $products),
                'Failed for input: ' . $input
            );
        }
    }
}
