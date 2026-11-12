<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\PhienAmGiongNoi;
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

    private function makeProduct(DanhMuc $danhMuc, string $name): SanPham
    {
        return SanPham::create([
            'MaDM' => $danhMuc->MaDM,
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
        $danhMuc = DanhMuc::create(['name' => 'Thuốc giảm đau, hạ sốt']);

        // "thuốc" xuất hiện 3 lần -> đạt ngưỡng; "Ceradan" chỉ 1 lần -> không.
        $this->makeProduct($danhMuc, 'Thuốc hạ sốt Hapacol 500');
        $this->makeProduct($danhMuc, 'Thuốc hạ sốt Efferalgan 500');
        $this->makeProduct($danhMuc, 'Thuốc giảm đau Panadol Extra');
        $this->makeProduct($danhMuc, 'Kem dưỡng ẩm Ceradan');

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
            PhienAmGiongNoi::where('kind', 'token')->where('term_folded', 'thuoc')->exists(),
            'token xuất hiện >= 3 lần phải vào index'
        );
        $this->assertFalse(
            PhienAmGiongNoi::where('kind', 'token')->where('term_folded', 'ceradan')->exists(),
            'token xuất hiện 1 lần không được vào index'
        );
    }

    public function test_it_is_idempotent(): void
    {
        $danhMuc = DanhMuc::create(['name' => 'Thuốc giảm đau, hạ sốt']);
        $this->makeProduct($danhMuc, 'Thuốc hạ sốt Hapacol 500');

        $this->artisan('voice:build-phonetics')->assertSuccessful();
        $first = PhienAmGiongNoi::count();

        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertSame($first, PhienAmGiongNoi::count());
    }

    public function test_long_keys_get_no_block_key(): void
    {
        $danhMuc = DanhMuc::create(['name' => 'Thuốc gan']);
        $long = 'Thuốc Silymax-F Hưng Việt điều trị viêm gan do virus, xơ gan, gan nhiễm mỡ';
        $this->makeProduct($danhMuc, $long);

        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertNull(PhienAmGiongNoi::where('term', $long)->value('block_key'));
        $this->assertNotNull(
            PhienAmGiongNoi::where('kind', 'token')->whereNotNull('block_key')->first(),
            'cụm ngắn phải có block_key'
        );
    }

    /**
     * MySQL mặc định utf8mb4_0900_ai_ci không phân biệt hoa/thường, mà khoá phiên
     * âm dùng chữ hoa để mã âm gộp. Đo được 65 nhóm khoá bị nhập nếu để mặc định.
     */
    public function test_keys_differing_only_in_case_are_distinct_rows(): void
    {
        PhienAmGiongNoi::insert([
            ['phonetic_key' => 'thUT', 'block_key' => 'th', 'term' => 'thuốc', 'term_folded' => 'thuoc', 'kind' => 'token', 'weight' => 1],
            ['phonetic_key' => 'thuT', 'block_key' => 'th', 'term' => 'thut', 'term_folded' => 'thut', 'kind' => 'token', 'weight' => 1],
        ]);

        $this->assertSame(2, PhienAmGiongNoi::count());
        $this->assertSame(1, PhienAmGiongNoi::whereIn('phonetic_key', ['thUT'])->count());
        $this->assertSame(0, PhienAmGiongNoi::whereIn('phonetic_key', ['thut'])->count());
    }

    public function test_empty_catalog_produces_empty_index(): void
    {
        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertSame(0, PhienAmGiongNoi::count());
    }

    public function test_new_product_appears_after_rebuild(): void
    {
        $danhMuc = DanhMuc::create(['name' => 'Thuốc ho']);
        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->makeProduct($danhMuc, 'Siro ho Prospan');
        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertDatabaseHas('voice_phonetics', [
            'term' => 'Siro ho Prospan',
            'phonetic_key' => VoicePhonetics::key('Siro ho Prospan'),
        ]);
    }
}
