<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\VoicePhonetic;
use App\Services\VoiceSearchNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bốn tầng, mỗi tầng đắt hơn tầng trước. Test ở đây khoá lại thứ tự đó: một câu
 * khớp được ở tầng rẻ KHÔNG được phép đi tới tầng đắt.
 *
 * Http::preventStrayRequests() trong setUp là cố ý: nếu một tầng lẽ ra không gọi
 * AI mà lại gọi, test phải đỏ ngay thay vì lặng lẽ đi qua nhánh degrade.
 */
class VoiceSearchNormalizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // Ngưỡng thật là 3 lượt xuất hiện, đo trên catalog 3.146 sản phẩm. Fixture
        // đây có một sản phẩm, nên với ngưỡng đó index chỉ có hai dòng tên đầy đủ và
        // KHÔNG có dòng `token` nào — tầng T1 sẽ không có gì để khớp. Hạ xuống 1 để
        // index của fixture có cùng hình dạng như index thật (có token), thay vì
        // nhân bản sản phẩm cho đủ ngưỡng.
        config(['search.voice.phonetic_min_hits' => 1]);

        $category = Category::create(['name' => 'Thuốc giảm đau, hạ sốt']);

        Product::create([
            'MaDM' => $category->MaDM,
            'name' => 'Thuốc hạ sốt Hapacol 500',
            'ingredients' => 'Paracetamol 500mg',
            'price' => 50000,
            'expiration_date' => now()->addYears(2),
            'stock_quantity' => 30,
            'reorder_point' => 10,
            'batch_number' => 'VN-1',
        ]);

        $this->artisan('voice:build-phonetics');
    }

    private function normalizer(): VoiceSearchNormalizer
    {
        return app(VoiceSearchNormalizer::class);
    }

    public function test_exact_match_wins_without_touching_the_phonetic_index(): void
    {
        $result = $this->normalizer()->normalize(['Thuốc hạ sốt Hapacol 500']);

        $this->assertSame('Thuốc hạ sốt Hapacol 500', $result['text']);
        $this->assertSame(VoiceSearchNormalizer::SOURCE_EXACT, $result['source']);
        $this->assertTrue($result['confident']);
    }

    public function test_regional_pronunciation_is_fixed_by_the_phonetic_tier(): void
    {
        $result = $this->normalizer()->normalize(['thuấc hạ sốc']);

        $this->assertSame(VoiceSearchNormalizer::SOURCE_PHONETIC, $result['source']);
        $this->assertStringContainsString('thuốc', mb_strtolower($result['text']));
        $this->assertTrue($result['confident']);
    }

    /** Chuỗi khách nói phải đi kèm mọi kết quả, để nút "hoàn tác" có gì mà trả về. */
    public function test_heard_is_always_the_first_alternative(): void
    {
        $result = $this->normalizer()->normalize(['thuấc hạ sốc', 'thuốc hạ sốt']);

        $this->assertSame('thuấc hạ sốc', $result['heard']);
    }

    public function test_a_later_alternative_can_win(): void
    {
        $result = $this->normalizer()->normalize(['xyz qwe rty', 'Thuốc hạ sốt Hapacol 500']);

        $this->assertSame('Thuốc hạ sốt Hapacol 500', $result['text']);
        $this->assertSame(VoiceSearchNormalizer::SOURCE_EXACT, $result['source']);
        $this->assertSame('xyz qwe rty', $result['heard']);
    }

    /** Tầng AI chưa có ở task này, nên câu lạ phải về nguyên văn, không phải rỗng. */
    public function test_unmatched_speech_falls_through_to_raw(): void
    {
        $result = $this->normalizer()->normalize(['bơ bơ rin gì đó']);

        $this->assertSame('bơ bơ rin gì đó', $result['text']);
        $this->assertSame(VoiceSearchNormalizer::SOURCE_RAW, $result['source']);
        $this->assertFalse($result['confident']);
    }

    public function test_empty_input_returns_empty_text_without_querying(): void
    {
        $result = $this->normalizer()->normalize([]);

        $this->assertSame('', $result['text']);
        $this->assertSame('', $result['heard']);
        $this->assertSame(VoiceSearchNormalizer::SOURCE_RAW, $result['source']);
    }
}
