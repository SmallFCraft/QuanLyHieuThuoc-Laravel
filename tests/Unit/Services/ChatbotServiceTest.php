<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Services\AuditLogService;
use App\Services\ChatbotService;
use App\Services\CheckoutService;
use App\Services\GeminiService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ChatbotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ChatbotService $chatbotService;
    protected MockObject $geminiService;

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

        $this->geminiService = $this->createMock(GeminiService::class);
        $inventoryService = $this->createMock(InventoryService::class);
        $checkoutService = $this->createMock(CheckoutService::class);
        $auditLogService = $this->createMock(AuditLogService::class);

        $this->chatbotService = new ChatbotService(
            $this->geminiService,
            $inventoryService,
            $checkoutService,
            $auditLogService
        );
    }

    public function test_search_products_returns_at_most_5_products(): void
    {
        $category = Category::factory()->create();

        Product::factory()->count(10)->create([
            'category_id' => $category->id,
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
            'category_id' => $category->id,
            'name' => 'Aspirin',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        Product::factory()->count(2)->create([
            'category_id' => $category->id,
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
            'category_id' => $category->id,
            'name' => 'Ibuprofen',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        Product::factory()->count(2)->create([
            'category_id' => $category->id,
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
            'category_id' => $category->id,
            'name' => 'Vitamin C',
            'stock_quantity' => 50,
            'expiration_date' => now()->addMonths(6),
        ]);

        $product2 = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Vitamin C',
            'stock_quantity' => 50,
            'expiration_date' => now()->addMonths(3),
        ]);

        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Vitamin C',
            'stock_quantity' => 50,
            'expiration_date' => now()->addMonths(9),
        ]);

        $results = $this->chatbotService->searchProducts('Vitamin C');

        $this->assertEquals($product2->id, $results->first()->id);
        $this->assertNotEquals($product1->id, $results->first()->id);
    }

    public function test_search_products_searches_multiple_fields(): void
    {
        $category = Category::factory()->create();

        $productByName = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Other ingredients',
            'usage' => 'Other usage',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $productByIngredients = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Pain Relief',
            'ingredients' => 'Contains Paracetamol',
            'usage' => 'Other usage',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $productByUsage = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Fever Medicine',
            'ingredients' => 'Other ingredients',
            'usage' => 'Use Paracetamol for fever',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        $results = $this->chatbotService->searchProducts('Paracetamol');

        $this->assertEquals(3, $results->count());
        $this->assertEqualsCanonicalizing(
            [$productByName->id, $productByIngredients->id, $productByUsage->id],
            $results->pluck('id')->all()
        );
    }

    public function test_search_products_loads_category_relationship(): void
    {
        $category = Category::factory()->create(['name' => 'Pain Relief']);

        Product::factory()->create([
            'category_id' => $category->id,
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
        $this->assertStringContainsString('sản phẩm', $prompt);
        $this->assertStringContainsString('danh mục', $prompt);
        $this->assertStringContainsString('Không bịa thêm giá', $prompt);
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

    public function test_process_message_uses_local_product_lookup_without_gemini(): void
    {
        $category = Category::factory()->create(['name' => 'Giảm đau']);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage('mua paracetamol', 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertStringContainsString('Paracetamol 500mg', $result['response']);
    }

    public function test_process_message_returns_symptom_guidance_without_product_lookup_for_general_symptom_question(): void
    {
        $this->geminiService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("T\u{00F4}i b\u{1ECB} ti\u{00EA}u ch\u{1EA3}y th\u{00EC} sao?", 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertEmpty($result['categories']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertStringContainsString('ORESOL', $result['response']);
    }

    public function test_process_message_returns_website_intro_without_product_search(): void
    {
        $category = Category::factory()->create([
            'name' => 'Vitamin',
            'description' => 'Nhóm sản phẩm bổ sung vitamin',
        ]);

        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Vitamin C 1000mg',
            'stock_quantity' => 10,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("Website n\u{00E0}y l\u{00E0} g\u{00EC}?", 1, []);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['products']);
        $this->assertNotEmpty($result['categories']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringContainsString('website', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_process_message_handles_website_intro_with_product_lookup(): void
    {
        $category = Category::factory()->create(['name' => 'Tiêu hóa']);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Smecta',
            'usage' => 'Hỗ trợ giảm tiêu chảy cấp và rối loạn tiêu hóa.',
            'stock_quantity' => 15,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("Website n\u{00E0}y l\u{00E0} g\u{00EC}? c\u{00F3} thu\u{1ED1}c smecta ko?", 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertEquals('Smecta', $result['products'][0]['name']);
        $this->assertStringContainsString('Medicare Pharmacy', $result['response']);
        $this->assertStringContainsString('Smecta', $result['response']);
    }

    public function test_process_message_handles_website_intro_with_product_usage_question(): void
    {
        $category = Category::factory()->create(['name' => 'Tiêu hóa']);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Smecta',
            'usage' => 'Hỗ trợ giảm tiêu chảy cấp và rối loạn tiêu hóa.',
            'stock_quantity' => 15,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->never())->method('chat');

        $result = $this->chatbotService->processMessage("Website n\u{00E0}y l\u{00E0} g\u{00EC}? c\u{00F3} thu\u{1ED1}c smecta ko? C\u{00F3} c\u{00F4}ng d\u{1EE5}ng hay t\u{00E1}c d\u{1EE5}ng g\u{00EC}?", 1, []);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['products']);
        $this->assertStringContainsString('Smecta', $result['response']);
        $this->assertStringContainsString('tiêu chảy', mb_strtolower($result['response'], 'UTF-8'));
    }

    public function test_category_lookup_response_returns_categories_without_gemini(): void
    {
        $category = Category::factory()->create([
            'name' => 'Vitamin',
            'description' => 'Nhóm sản phẩm hỗ trợ bổ sung vitamin',
        ]);

        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Vitamin C 1000mg',
            'stock_quantity' => 10,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->never())->method('chat');

        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('buildCategoryLookupResponse');
        $method->setAccessible(true);

        $result = $method->invoke($this->chatbotService, 'xem danh mục vitamin');

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['categories']);
        $this->assertEquals('Vitamin', $result['categories'][0]['name']);
        $this->assertStringContainsString('danh mục', $result['response']);
    }

    public function test_process_message_uses_gemini_for_symptom_queries_and_returns_disclaimer(): void
    {
        $category = Category::factory()->create(['name' => 'Giảm đau']);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->once())
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
            'category_id' => $category->id,
            'name' => 'Smecta',
            'usage' => 'Hỗ trợ giảm tiêu chảy cấp và rối loạn tiêu hóa.',
            'stock_quantity' => 30,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->once())
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
            'category_id' => $category->id,
            'name' => 'Smecta',
            'usage' => 'Hỗ trợ giảm tiêu chảy cấp và rối loạn tiêu hóa.',
            'stock_quantity' => 30,
            'expiration_date' => now()->addYear(),
        ]);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Lactulose Syrup',
            'usage' => 'Hỗ trợ táo bón và làm mềm phân.',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->once())
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
            'category_id' => $category->id,
            'name' => 'Canxi Kid Plus',
            'usage' => 'Hỗ trợ tăng chiều cao và phát triển xương cho trẻ.',
            'stock_quantity' => 25,
            'expiration_date' => now()->addYear(),
        ]);

        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Vitamin C Immunity',
            'usage' => 'Hỗ trợ tăng sức đề kháng và giảm mệt mỏi.',
            'stock_quantity' => 40,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->never())->method('chat');

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
            'category_id' => $category->id,
            'name' => 'Efferalgan 500mg',
            'usage' => 'Giảm đau, hạ sốt nhanh.',
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->once())
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
            'category_id' => $category->id,
            'name' => 'Isosorbide Dinitrate 5mg',
            'usage' => 'Điều trị đau thắt ngực.',
            'stock_quantity' => 200,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->once())
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

    public function test_process_message_with_url_only_returns_link_guidance_instead_of_symptom_advice(): void
    {
        $this->geminiService->expects($this->never())->method('chat');

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

    public function test_process_message_with_nearby_pharmacy_location_request_does_not_return_categories(): void
    {
        $category = Category::factory()->create(['name' => 'Thuốc tiêu hóa']);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Smecta',
            'usage' => 'Điều trị tiêu chảy.',
            'stock_quantity' => 15,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->never())->method('chat');

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
        $this->geminiService->method('chat')->willReturn([
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
            'category_id' => $category->id,
            'name' => 'Paracetamol 500mg',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
            'usage' => 'Giảm đau và hạ sốt',
        ]);

        $this->geminiService->method('chat')->willReturn([
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
        $this->geminiService->method('chat')->willThrowException(new \Exception('API Error'));

        $result = $this->chatbotService->recommendBySymptoms('đau đầu');

        $this->assertArrayHasKey('error', $result);
        $this->assertEmpty($result['products']);
        $this->assertTrue($result['show_disclaimer']);
    }
    public function test_process_message_strips_internal_tool_narration_from_assistant_response(): void
    {
        $category = Category::factory()->create(['name' => 'Cảm cúm']);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Decolgen',
            'usage' => 'Hỗ trợ giảm các triệu chứng cảm cúm, nghẹt mũi và đau đầu.',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        $this->geminiService->expects($this->once())
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
