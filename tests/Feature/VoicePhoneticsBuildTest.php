<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\VoicePhonetic;
use App\Support\VoicePhonetics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Index phiên âm là bảng tra cứu dựng lại từ catalog, không phải dữ liệu nghiệp
 * vụ. Nó phải dựng lại được bất cứ lúc nào và cho cùng một kết quả.
 */
class VoicePhoneticsBuildTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(Category $category, string $name): Product
    {
        return Product::create([
            'MaDM' => $category->MaDM,
            'name' => $name,
            'ingredients' => 'Paracetamol 500mg',
            'price' => 50000,
            'expiration_date' => now()->addYears(2),
            'stock_quantity' => 30,
            'reorder_point' => 10,
            'batch_number' => 'VP-' . md5($name),
        ]);
    }

    public function test_it_indexes_product_names_categories_and_frequent_tokens(): void
    {
        $category = Category::create(['name' => 'Thuốc giảm đau, hạ sốt']);

        // "thuốc" xuất hiện 3 lần -> đạt ngưỡng; "Ceradan" chỉ 1 lần -> không.
        $this->makeProduct($category, 'Thuốc hạ sốt Hapacol 500');
        $this->makeProduct($category, 'Thuốc hạ sốt Efferalgan 500');
        $this->makeProduct($category, 'Thuốc giảm đau Panadol Extra');
        $this->makeProduct($category, 'Kem dưỡng ẩm Ceradan');

        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertDatabaseHas('voice_phonetics', [
            'term' => 'Thuốc hạ sốt Hapacol 500',
            'kind' => 'product',
        ]);
        $this->assertDatabaseHas('voice_phonetics', [
            'term' => 'Thuốc giảm đau, hạ sốt',
            'kind' => 'category',
        ]);
        $this->assertTrue(
            VoicePhonetic::where('kind', 'token')->where('term_folded', 'thuoc')->exists(),
            'token xuất hiện >= 3 lần phải vào index'
        );
        $this->assertFalse(
            VoicePhonetic::where('kind', 'token')->where('term_folded', 'ceradan')->exists(),
            'token xuất hiện 1 lần không được vào index'
        );
    }

    public function test_it_is_idempotent(): void
    {
        $category = Category::create(['name' => 'Thuốc giảm đau, hạ sốt']);
        $this->makeProduct($category, 'Thuốc hạ sốt Hapacol 500');

        $this->artisan('voice:build-phonetics')->assertSuccessful();
        $first = VoicePhonetic::count();

        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertSame($first, VoicePhonetic::count());
    }

    public function test_long_keys_get_no_block_key(): void
    {
        $category = Category::create(['name' => 'Thuốc gan']);
        $long = 'Thuốc Silymax-F Hưng Việt điều trị viêm gan do virus, xơ gan, gan nhiễm mỡ';
        $this->makeProduct($category, $long);

        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertNull(VoicePhonetic::where('term', $long)->value('block_key'));
        $this->assertNotNull(
            VoicePhonetic::where('kind', 'token')->whereNotNull('block_key')->first(),
            'cụm ngắn phải có block_key'
        );
    }

    /**
     * MySQL mặc định utf8mb4_0900_ai_ci không phân biệt hoa/thường, mà khoá phiên
     * âm dùng chữ hoa để mã âm gộp. Đo được 65 nhóm khoá bị nhập nếu để mặc định.
     */
    public function test_keys_differing_only_in_case_are_distinct_rows(): void
    {
        VoicePhonetic::insert([
            ['phonetic_key' => 'thUT', 'block_key' => 'th', 'term' => 'thuốc', 'term_folded' => 'thuoc', 'kind' => 'token', 'weight' => 1],
            ['phonetic_key' => 'thuT', 'block_key' => 'th', 'term' => 'thut', 'term_folded' => 'thut', 'kind' => 'token', 'weight' => 1],
        ]);

        $this->assertSame(2, VoicePhonetic::count());
        $this->assertSame(1, VoicePhonetic::whereIn('phonetic_key', ['thUT'])->count());
        $this->assertSame(0, VoicePhonetic::whereIn('phonetic_key', ['thut'])->count());
    }

    public function test_empty_catalog_produces_empty_index(): void
    {
        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertSame(0, VoicePhonetic::count());
    }

    public function test_new_product_appears_after_rebuild(): void
    {
        $category = Category::create(['name' => 'Thuốc ho']);
        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->makeProduct($category, 'Siro ho Prospan');
        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertDatabaseHas('voice_phonetics', [
            'term' => 'Siro ho Prospan',
            'phonetic_key' => VoicePhonetics::key('Siro ho Prospan'),
        ]);
    }
}
