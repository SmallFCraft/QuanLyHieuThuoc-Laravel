<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Services\AuditLogService;
use App\Services\ChatbotService;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use App\Services\OpenAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ChatbotService $chatbotService;

    /**
     * Define database connection for tests.
     */
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

        // Mock dependencies
        $openAIService = $this->createMock(OpenAIService::class);
        $inventoryService = $this->createMock(InventoryService::class);
        $checkoutService = $this->createMock(CheckoutService::class);
        $auditLogService = $this->createMock(AuditLogService::class);

        $this->chatbotService = new ChatbotService(
            $openAIService,
            $inventoryService,
            $checkoutService,
            $auditLogService
        );
    }

    /**
     * Test searchProducts returns at most 5 products.
     */
    public function test_search_products_returns_at_most_5_products(): void
    {
        // Create a category
        $category = Category::factory()->create();

        // Create 10 products with stock and not expired
        Product::factory()->count(10)->create([
            'category_id' => $category->id,
            'name' => 'Paracetamol 500mg',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
        ]);

        $results = $this->chatbotService->searchProducts('Paracetamol');

        $this->assertLessThanOrEqual(5, $results->count());
    }

    /**
     * Test searchProducts only returns products with stock > 0.
     */
    public function test_search_products_only_returns_in_stock_products(): void
    {
        $category = Category::factory()->create();

        // Create products with stock
        Product::factory()->count(3)->create([
            'category_id' => $category->id,
            'name' => 'Aspirin',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        // Create products without stock
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

    /**
     * Test searchProducts only returns non-expired products.
     */
    public function test_search_products_only_returns_non_expired_products(): void
    {
        $category = Category::factory()->create();

        // Create non-expired products
        Product::factory()->count(3)->create([
            'category_id' => $category->id,
            'name' => 'Ibuprofen',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        // Create expired products
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

    /**
     * Test searchProducts applies FEFO ordering.
     */
    public function test_search_products_applies_fefo_ordering(): void
    {
        $category = Category::factory()->create();

        // Create products with different expiration dates
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

        $product3 = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Vitamin C',
            'stock_quantity' => 50,
            'expiration_date' => now()->addMonths(9),
        ]);

        $results = $this->chatbotService->searchProducts('Vitamin C');

        // First product should have earliest expiration date
        $this->assertEquals($product2->id, $results->first()->id);
    }

    /**
     * Test searchProducts searches across name, ingredients, and usage fields.
     */
    public function test_search_products_searches_multiple_fields(): void
    {
        $category = Category::factory()->create();

        // Product matching by name
        $productByName = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Other ingredients',
            'usage' => 'Other usage',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        // Product matching by ingredients
        $productByIngredients = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Pain Relief',
            'ingredients' => 'Contains Paracetamol',
            'usage' => 'Other usage',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        // Product matching by usage
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
        $resultIds = $results->pluck('id')->toArray();
        $this->assertContains($productByName->id, $resultIds);
        $this->assertContains($productByIngredients->id, $resultIds);
        $this->assertContains($productByUsage->id, $resultIds);
    }

    /**
     * Test searchProducts loads category relationship.
     */
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
        $product = $results->first();
        $this->assertTrue($product->relationLoaded('category'));
        $this->assertEquals('Pain Relief', $product->category->name);
    }

    /**
     * Test searchProducts returns empty collection when no matches.
     */
    public function test_search_products_returns_empty_when_no_matches(): void
    {
        $results = $this->chatbotService->searchProducts('NonexistentProduct');

        $this->assertEmpty($results);
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    /**
     * Test getSystemPrompt returns Vietnamese prompt.
     * Validates: Requirements 3.1, 3.3, 3.5, 13.1, 13.2, 14.1
     */
    public function test_get_system_prompt_returns_vietnamese_prompt(): void
    {
        $prompt = $this->chatbotService->getSystemPrompt();

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
        
        // Verify Vietnamese content
        $this->assertStringContainsString('Bạn là trợ lý AI', $prompt);
        $this->assertStringContainsString('nhà thuốc', $prompt);
        $this->assertStringContainsString('tiếng Việt', $prompt);
    }

    /**
     * Test system prompt includes medical disclaimer requirements.
     * Validates: Requirements 3.5, 13.1, 13.2
     */
    public function test_system_prompt_includes_medical_disclaimer_requirements(): void
    {
        $prompt = $this->chatbotService->getSystemPrompt();

        // Verify disclaimer requirements
        $this->assertStringContainsString('tham khảo ý kiến bác sĩ', $prompt);
        $this->assertStringContainsString('Không đưa ra chẩn đoán y khoa', $prompt);
        $this->assertStringContainsString('tham khảo dược sĩ hoặc bác sĩ', $prompt);
    }

    /**
     * Test system prompt includes symptom handling instructions.
     * Validates: Requirements 3.1, 3.3
     */
    public function test_system_prompt_includes_symptom_handling_instructions(): void
    {
        $prompt = $this->chatbotService->getSystemPrompt();

        // Verify symptom handling instructions
        $this->assertStringContainsString('triệu chứng', $prompt);
        $this->assertStringContainsString('search_products', $prompt);
        $this->assertStringContainsString('Giải thích lý do', $prompt);
    }

    /**
     * Test system prompt defines pharmacy assistant role.
     * Validates: Requirements 3.1, 14.1
     */
    public function test_system_prompt_defines_pharmacy_assistant_role(): void
    {
        $prompt = $this->chatbotService->getSystemPrompt();

        // Verify role definition
        $this->assertStringContainsString('tìm kiếm sản phẩm dược phẩm', $prompt);
        $this->assertStringContainsString('Đề xuất sản phẩm dựa trên triệu chứng', $prompt);
        $this->assertStringContainsString('lịch sự và chuyên nghiệp', $prompt);
    }

    /**
     * Test function schema structure for OpenAI function calling.
     * Validates: Requirements 3.1, 3.3
     */
    public function test_function_schema_has_correct_structure(): void
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('getFunctionSchema');
        $method->setAccessible(true);
        
        $schema = $method->invoke($this->chatbotService);

        $this->assertIsArray($schema);
        $this->assertNotEmpty($schema);
        
        // Verify first function is search_products
        $searchFunction = $schema[0];
        $this->assertEquals('search_products', $searchFunction['name']);
        $this->assertStringContainsString('Tìm kiếm sản phẩm', $searchFunction['description']);
    }

    /**
     * Test function schema parameters include required fields.
     * Validates: Requirements 3.1, 3.3
     */
    public function test_function_schema_parameters_include_required_fields(): void
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('getFunctionSchema');
        $method->setAccessible(true);
        
        $schema = $method->invoke($this->chatbotService);
        $searchFunction = $schema[0];
        $parameters = $searchFunction['parameters'];

        // Verify parameters structure
        $this->assertEquals('object', $parameters['type']);
        $this->assertArrayHasKey('properties', $parameters);
        $this->assertArrayHasKey('required', $parameters);
        
        // Verify query parameter
        $this->assertArrayHasKey('query', $parameters['properties']);
        $this->assertEquals('string', $parameters['properties']['query']['type']);
        $this->assertContains('query', $parameters['required']);
    }

    /**
     * Test function schema includes optional parameters.
     * Validates: Requirements 3.1, 3.3
     */
    public function test_function_schema_includes_optional_parameters(): void
    {
        // Use reflection to access protected method
        $reflection = new \ReflectionClass($this->chatbotService);
        $method = $reflection->getMethod('getFunctionSchema');
        $method->setAccessible(true);
        
        $schema = $method->invoke($this->chatbotService);
        $searchFunction = $schema[0];
        $properties = $searchFunction['parameters']['properties'];

        // Verify optional parameters
        $this->assertArrayHasKey('category', $properties);
        $this->assertArrayHasKey('symptoms', $properties);
        
        // Verify symptoms is array type
        $this->assertEquals('array', $properties['symptoms']['type']);
        $this->assertArrayHasKey('items', $properties['symptoms']);
    }

    /**
     * Test recommendBySymptoms includes disclaimer.
     * Validates: Requirements 3.5, 13.3
     */
    public function test_recommend_by_symptoms_includes_disclaimer(): void
    {
        // Mock OpenAI service to return a response without function call
        $openAIService = $this->createMock(OpenAIService::class);
        $openAIService->method('chat')->willReturn([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Tôi khuyên bạn nên tham khảo ý kiến bác sĩ.'
                    ]
                ]
            ]
        ]);

        $chatbotService = new ChatbotService(
            $openAIService,
            $this->createMock(InventoryService::class),
            $this->createMock(CheckoutService::class),
            $this->createMock(AuditLogService::class)
        );

        $result = $chatbotService->recommendBySymptoms('đau đầu');

        $this->assertTrue($result['show_disclaimer']);
        $this->assertArrayHasKey('disclaimer', $result);
        $this->assertStringContainsString('AI tạo ra có thể không chính xác', $result['disclaimer']);
        $this->assertStringContainsString('tham khảo ý kiến dược sĩ hoặc bác sĩ', $result['disclaimer']);
    }

    /**
     * Test recommendBySymptoms returns products and explanations.
     * Validates: Requirements 3.1, 3.2, 3.3, 3.4
     */
    public function test_recommend_by_symptoms_returns_products_and_explanations(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Paracetamol 500mg',
            'stock_quantity' => 100,
            'expiration_date' => now()->addYear(),
            'usage' => 'Giảm đau và hạ sốt'
        ]);

        // Mock OpenAI service to return function call
        $openAIService = $this->createMock(OpenAIService::class);
        $openAIService->method('chat')->willReturnOnConsecutiveCalls(
            // First call: function call response
            [
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'function_call' => [
                                'name' => 'search_products',
                                'arguments' => json_encode(['query' => 'Paracetamol'])
                            ]
                        ]
                    ]
                ]
            ],
            // Second call: explanation response
            [
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Paracetamol 500mg giúp giảm đau đầu và hạ sốt hiệu quả.'
                        ]
                    ]
                ]
            ]
        );

        $chatbotService = new ChatbotService(
            $openAIService,
            $this->createMock(InventoryService::class),
            $this->createMock(CheckoutService::class),
            $this->createMock(AuditLogService::class)
        );

        $result = $chatbotService->recommendBySymptoms('đau đầu và sốt');

        $this->assertArrayHasKey('products', $result);
        $this->assertArrayHasKey('explanations', $result);
        $this->assertNotEmpty($result['products']);
        $this->assertIsArray($result['explanations']);
    }

    /**
     * Test recommendBySymptoms handles errors gracefully.
     * Validates: Requirements 17.1, 17.2
     */
    public function test_recommend_by_symptoms_handles_errors_gracefully(): void
    {
        // Mock OpenAI service to throw exception
        $openAIService = $this->createMock(OpenAIService::class);
        $openAIService->method('chat')->willThrowException(new \Exception('API Error'));

        $chatbotService = new ChatbotService(
            $openAIService,
            $this->createMock(InventoryService::class),
            $this->createMock(CheckoutService::class),
            $this->createMock(AuditLogService::class)
        );

        $result = $chatbotService->recommendBySymptoms('đau đầu');

        $this->assertArrayHasKey('error', $result);
        $this->assertEmpty($result['products']);
        $this->assertTrue($result['show_disclaimer']);
        $this->assertStringContainsString('Không thể xử lý yêu cầu', $result['error']);
    }

    /**
     * Test recommendBySymptoms returns Vietnamese error messages.
     * Validates: Requirements 14.6, 17.1
     */
    public function test_recommend_by_symptoms_returns_vietnamese_error_messages(): void
    {
        // Mock OpenAI service to throw exception
        $openAIService = $this->createMock(OpenAIService::class);
        $openAIService->method('chat')->willThrowException(new \Exception('API Error'));

        $chatbotService = new ChatbotService(
            $openAIService,
            $this->createMock(InventoryService::class),
            $this->createMock(CheckoutService::class),
            $this->createMock(AuditLogService::class)
        );

        $result = $chatbotService->recommendBySymptoms('đau đầu');

        $this->assertArrayHasKey('error', $result);
        // Verify Vietnamese error message
        $this->assertMatchesRegularExpression('/[àáảãạăắằẳẵặâấầẩẫậèéẻẽẹêếềểễệìíỉĩịòóỏõọôốồổỗộơớờởỡợùúủũụưứừửữựỳýỷỹỵđ]/u', $result['error']);
    }
}

