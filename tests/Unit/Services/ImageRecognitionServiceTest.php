<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Services\AiProviderService;
use App\Services\AuditLogService;
use App\Services\ImageRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImageRecognitionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ImageRecognitionService $service;
    protected Category $category;

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

        $this->service = new ImageRecognitionService(
            $this->createMock(AuditLogService::class),
            $this->createMock(AiProviderService::class)
        );

        $this->category = Category::factory()->create(['name' => 'Vitamin và khoáng chất']);
    }

    protected function createProduct(string $name, string $ingredients = '', string $usage = ''): Product
    {
        return Product::factory()->create([
            'MaDM' => $this->category->MaDM,
            'name' => $name,
            'ingredients' => $ingredients,
            'usage' => $usage,
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function parsedVitaminA(): array
    {
        return [
            'name' => 'Vitamin A 5000 IU',
            'dosage' => '5000 IU',
            'quantity' => 1,
            'active_ingredients' => ['Retinol', 'Vitamin A'],
            'generic_names' => ['Retinol'],
            'category' => 'Vitamin và khoáng chất',
            'uses' => ['Bổ sung vitamin A', 'Hỗ trợ thị lực'],
            'health_goals' => ['immunity', 'eye health'],
            'visible_text' => 'Vitamin A 5000 IU Mekophar hộp 10 viên nang cứng',
            'instructions' => 'Uống 1 viên mỗi ngày',
        ];
    }

    public function test_vitamin_a_image_is_not_matched_to_an_unrelated_supplement(): void
    {
        $this->createProduct('Zinc 50mg', 'Zinc sulfate 50mg', 'Bổ sung kẽm, tăng cường miễn dịch. Uống 1 viên/ngày');
        $this->createProduct('Iron + Folic Acid', 'Sắt fumarate, Acid folic', 'Bổ sung sắt. Uống 1 viên mỗi ngày');

        $matched = $this->service->matchProducts([$this->parsedVitaminA()]);

        $this->assertCount(1, $matched);
        $this->assertFalse($matched[0]['matched']);
        $this->assertNull($matched[0]['product']);
        $this->assertSame('Vitamin A 5000 IU', $matched[0]['parsed_name']);
    }

    public function test_vitamin_a_image_is_not_matched_to_a_different_vitamin_letter(): void
    {
        $this->createProduct('Vitamin C 1000mg', 'Acid ascorbic 1000mg', 'Bổ sung vitamin C, tăng sức đề kháng');
        $this->createProduct('Vitamin E 400IU', 'Alpha tocopherol 400IU', 'Bổ sung vitamin E');

        $matched = $this->service->matchProducts([$this->parsedVitaminA()]);

        $this->assertFalse($matched[0]['matched']);
    }

    public function test_unmatched_vitamin_image_suggests_products_from_the_same_family(): void
    {
        $this->createProduct('Vitamin C 1000mg', 'Acid ascorbic 1000mg', 'Bổ sung vitamin C, tăng sức đề kháng');
        $this->createProduct('Multivitamin', 'Vitamin nhóm B, Vitamin C', 'Bổ sung vitamin tổng hợp mỗi ngày');
        $this->createProduct('Zinc 50mg', 'Zinc sulfate 50mg', 'Bổ sung kẽm, tăng cường miễn dịch');

        $matched = $this->service->matchProducts([$this->parsedVitaminA()]);
        $suggestionNames = array_column($matched[0]['suggestions'] ?? [], 'name');

        $this->assertFalse($matched[0]['matched']);
        $this->assertNotEmpty($suggestionNames);
        $this->assertNotContains('Zinc 50mg', $suggestionNames);
    }

    public function test_equivalent_brand_name_still_matches_the_catalog_product(): void
    {
        $product = $this->createProduct('Vitamin C 1000mg', 'Acid ascorbic 1000mg', 'Bổ sung vitamin C, tăng sức đề kháng');
        $this->createProduct('Zinc 50mg', 'Zinc sulfate 50mg', 'Bổ sung kẽm');

        $matched = $this->service->matchProducts([[
            'name' => 'Blackmores Bio C',
            'quantity' => 31,
            'dosage' => '1000mg',
            'instructions' => 'Reduces the severity and duration of common cold symptoms',
        ]]);

        $this->assertTrue($matched[0]['matched']);
        $this->assertSame($product->MaSP, $matched[0]['product']['id']);
    }

    public function test_exact_product_name_is_matched(): void
    {
        $product = $this->createProduct('Zinc 50mg', 'Zinc sulfate 50mg', 'Bổ sung kẽm');

        $matched = $this->service->matchProducts([[
            'name' => 'Zinc 50mg',
            'dosage' => '50mg',
            'active_ingredients' => ['Zinc sulfate'],
        ]]);

        $this->assertTrue($matched[0]['matched']);
        $this->assertSame($product->MaSP, $matched[0]['product']['id']);
        $this->assertGreaterThanOrEqual(0.9, $matched[0]['confidence']);
    }

    public function test_accent_free_parsed_name_matches_accented_catalog_name(): void
    {
        $product = $this->createProduct('Nước muối sinh lý 0.9%', 'Natri clorid 0.9%', 'Rửa mũi, vệ sinh mắt');

        $matched = $this->service->matchProducts([[
            'name' => 'Nuoc muoi sinh ly',
            'active_ingredients' => ['Natri clorid'],
        ]]);

        $this->assertTrue($matched[0]['matched']);
        $this->assertSame($product->MaSP, $matched[0]['product']['id']);
    }

    public function test_dosage_decides_between_two_strengths_of_the_same_medicine(): void
    {
        $this->createProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau, hạ sốt');
        $target = $this->createProduct('Paracetamol 650mg', 'Paracetamol 650mg', 'Giảm đau, hạ sốt');

        $matched = $this->service->matchProducts([[
            'name' => 'Paracetamol 650mg',
            'dosage' => '650mg',
            'active_ingredients' => ['Paracetamol'],
        ]]);

        $this->assertTrue($matched[0]['matched']);
        $this->assertSame($target->MaSP, $matched[0]['product']['id']);
    }

    public function test_same_product_in_two_batches_is_not_treated_as_ambiguous(): void
    {
        $earliest = Product::factory()->create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Vitamin C 1000mg',
            'ingredients' => 'Acid ascorbic 1000mg',
            'stock_quantity' => 10,
            'expiration_date' => now()->addMonths(3),
        ]);
        Product::factory()->create([
            'MaDM' => $this->category->MaDM,
            'name' => 'Vitamin C 1000mg',
            'ingredients' => 'Acid ascorbic 1000mg',
            'stock_quantity' => 40,
            'expiration_date' => now()->addYears(2),
        ]);

        $matched = $this->service->matchProducts([[
            'name' => 'Blackmores Bio C',
            'dosage' => '1000mg',
        ]]);

        $this->assertTrue($matched[0]['matched']);
        $this->assertSame($earliest->MaSP, $matched[0]['product']['id'], 'FEFO batch should win');
    }
}
