<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\VoicePhonetic;
use App\Services\VoiceSearchNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

        // T1 sửa NỬA câu: `thuấc` -> `Thuốc` nhưng `sốc` vẫn là `sốc`, nên chuỗi trả
        // về không phải tên sản phẩm nào (`soc` không có trong name_folded nào, và
        // storefrontSearch('Thuốc hạ sốc') ra 0 sản phẩm). Sửa được một phần vẫn là
        // sửa — hai assert trên khoá lại điều đó — nhưng nó KHÔNG được tuyên bố chắc
        // chắn, không thì client nói "Nghe được" trên một trang kết quả rỗng.
        $this->assertFalse($result['confident']);
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

    /** Bật tầng AI và giả lập một câu trả lời. */
    private function fakeAi(string $answer): void
    {
        config(['search.voice.ai' => true]);

        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => $answer]]],
        ])]);
    }

    public function test_ai_tier_runs_only_when_the_cheap_tiers_miss(): void
    {
        $this->fakeAi('thuốc hạ sốt');

        $result = $this->normalizer()->normalize(['bơ bơ rin gì đó']);

        $this->assertSame(VoiceSearchNormalizer::SOURCE_AI, $result['source']);
        $this->assertSame('thuốc hạ sốt', $result['text']);
        // Khách nên xem lại trước khi bấm Enter: đây là suy đoán, không phải khớp.
        $this->assertFalse($result['confident']);
        Http::assertSentCount(1);
    }

    public function test_exact_and_phonetic_hits_never_call_the_ai(): void
    {
        $this->fakeAi('không được gọi');

        $this->normalizer()->normalize(['Thuốc hạ sốt Hapacol 500']);
        $this->normalizer()->normalize(['thuấc hạ sốc']);

        Http::assertNothingSent();
    }

    /**
     * Luật an toàn: đo được provider trả "parasitome" cho audio nói
     * "paracetamol". Một lớp AI tự đặt tên thuốc là lỗi an toàn, nên chuỗi nào
     * không khớp lại được catalog thì bị bỏ.
     */
    public function test_an_invented_drug_name_is_discarded(): void
    {
        $this->fakeAi('parasitome non-transmembrane');

        $result = $this->normalizer()->normalize(['pa ra xi tôm gì đó']);

        $this->assertSame(VoiceSearchNormalizer::SOURCE_RAW, $result['source']);
        $this->assertSame('pa ra xi tôm gì đó', $result['text']);
    }

    public function test_the_same_utterance_calls_the_ai_once(): void
    {
        $this->fakeAi('thuốc hạ sốt');

        $this->normalizer()->normalize(['bơ bơ rin gì đó']);
        $this->normalizer()->normalize(['bơ bơ rin gì đó']);

        Http::assertSentCount(1);
    }

    /** Kết quả bị bỏ cũng được cache: hỏi lại cùng câu không đáng một call nữa. */
    public function test_a_discarded_answer_is_cached_too(): void
    {
        $this->fakeAi('parasitome');

        $this->normalizer()->normalize(['pa ra xi tôm gì đó']);
        $this->normalizer()->normalize(['pa ra xi tôm gì đó']);

        Http::assertSentCount(1);
    }

    public function test_the_breaker_opens_after_repeated_failures(): void
    {
        config(['search.voice.ai' => true, 'search.voice.breaker_failures' => 2]);
        Http::fake(['*' => Http::response('gateway down', 502)]);

        // Câu khác nhau nên cache không che được: mỗi lượt là một call thật.
        $this->normalizer()->normalize(['câu lạ một']);
        $this->normalizer()->normalize(['câu lạ hai']);
        $result = $this->normalizer()->normalize(['câu lạ ba']);

        $this->assertSame(VoiceSearchNormalizer::SOURCE_RAW, $result['source']);
        $this->assertSame('câu lạ ba', $result['text']);
        Http::assertSentCount(2);
    }

    public function test_ai_failure_still_returns_what_the_customer_said(): void
    {
        config(['search.voice.ai' => true]);
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $result = $this->normalizer()->normalize(['câu lạ nào đó']);

        $this->assertSame('câu lạ nào đó', $result['text']);
        $this->assertSame(VoiceSearchNormalizer::SOURCE_RAW, $result['source']);
    }

    public function test_the_ai_tier_can_be_switched_off(): void
    {
        config(['search.voice.ai' => false]);

        $result = $this->normalizer()->normalize(['bơ bơ rin gì đó']);

        $this->assertSame(VoiceSearchNormalizer::SOURCE_RAW, $result['source']);
        Http::assertNothingSent();
    }

    /**
     * Chrome vi-VN LUÔN chấm hết câu: nói "thuốc đau đầu" trả về "Tìm thúc đau đầu.".
     * fold() bỏ dấu câu khi so khớp nhưng chuỗi TRẢ VỀ thì không, nên dấu chấm đi vào
     * ô tìm kiếm, vào `?q=` và vào nhãn "Xem tất cả kết quả cho …" (US-11).
     *
     * Cụm mệnh lệnh là nhiễu đo được trên catalog: `tim` khớp 81 sản phẩm (tim là cơ
     * quan), `cho` khớp 639, `toi` khớp 87. Nên "Tìm giúp tôi men vi sinh" được T0
     * tuyên bố exact nhờ CHÍNH từ mệnh lệnh rồi dừng — tầng phiên âm không bao giờ
     * chạy để sửa phần khách thật sự hỏi (US-12).
     *
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function noisyUtterances(): array
    {
        return [
            'dấu chấm cuối câu' => ['thuốc hạ sốt.', ['.']],
            'dấu hỏi cuối câu' => ['Có thuốc ho không?', ['?']],
            'dấu phẩy cuối câu' => ['vitamin C,', [',']],
            'cụm mệnh lệnh ba từ' => ['Tìm giúp tôi men vi sinh', ['Tìm giúp tôi']],
            'cụm mệnh lệnh + dấu câu' => ['Cho tôi men tiêu hoá.', ['Cho tôi', '.']],
            'câu đầy đủ chủ ngữ' => ['Tôi muốn mua khẩu trang y tế', ['Tôi muốn mua']],
        ];
    }

    /**
     * @param array<int, string> $absent
     */
    #[DataProvider('noisyUtterances')]
    public function test_punctuation_and_command_phrases_never_reach_the_query(string $said, array $absent): void
    {
        $result = $this->normalizer()->normalize([$said]);

        foreach ($absent as $noise) {
            $this->assertStringNotContainsString($noise, $result['text']);
        }

        // Nút "Hoàn tác" trả lại đúng chữ khách nói, nên heard phải còn nguyên nhiễu.
        $this->assertSame($said, $result['heard']);
        $this->assertNotSame('', $result['text']);
    }

    /**
     * Làm sạch không được ăn vào phần mang thông tin: dấu câu GIỮA câu là một phần của
     * tên hàng ("omega-3", "vitamin B6"), và một cụm mệnh lệnh chỉ bị bỏ khi phần còn
     * lại vẫn là một truy vấn — khách nói đúng một từ "Tìm" thì đó là tất cả những gì
     * ta có, trả chuỗi rỗng còn tệ hơn trả nhiễu.
     *
     * @return array<string, array{0: string}>
     */
    public static function utterancesKeptWholeProvider(): array
    {
        return [
            'chỉ có cụm mệnh lệnh' => ['Tìm'],
            'gạch nối giữa câu' => ['omega-3'],
            'chữ số dính tên vitamin' => ['vitamin B6'],
        ];
    }

    #[DataProvider('utterancesKeptWholeProvider')]
    public function test_cleaning_keeps_an_utterance_that_carries_no_noise(string $said): void
    {
        $result = $this->normalizer()->normalize([$said]);

        $this->assertSame($said, $result['text']);
        $this->assertSame($said, $result['heard']);
    }

    /** Cụm dài phải được thử trước cụm ngắn, không thì `tim` ăn trước `tim kiem`. */
    public function test_the_longest_command_phrase_wins(): void
    {
        $result = $this->normalizer()->normalize(['Tìm kiếm men vi sinh']);

        $this->assertSame('men vi sinh', $result['text']);
    }

    /**
     * Từ ghép từ HAI sản phẩm khác nhau không phải một truy vấn chắc chắn.
     *
     * isRealQuery() kiểm từng từ độc lập nên "vitamin khẩu trang" đi qua được, dù
     * không sản phẩm nào có cả hai. Đo trên catalog thật 2026-09-02: 33/94 ca trả
     * confident mà sai, 15 ca trong đó catalog trả 0 sản phẩm.
     */
    public function test_words_from_different_products_are_not_confident(): void
    {
        $category = Category::create(['name' => 'Khẩu trang']);

        Product::create([
            'MaDM' => $category->MaDM,
            'name' => 'Khẩu trang y tế 4 lớp',
            'ingredients' => 'Vải không dệt',
            'price' => 30000,
            'expiration_date' => now()->addYears(3),
            'stock_quantity' => 100,
            'reorder_point' => 10,
            'batch_number' => 'KT-1',
        ]);

        $this->artisan('voice:build-phonetics');

        $result = $this->normalizer()->normalize(['hapacol khẩu trang']);

        $this->assertFalse($result['confident']);
        // Chuỗi trả về KHÔNG đổi: cổng chỉ hạ cờ, không hạ độ phủ.
        $this->assertSame('hapacol khẩu trang', $result['text']);
    }

    public function test_words_inside_one_product_name_stay_confident(): void
    {
        $result = $this->normalizer()->normalize(['thuốc hạ sốt hapacol']);

        $this->assertTrue($result['confident']);
        $this->assertSame('exact', $result['source']);
    }

    /**
     * T1 chỉ được tuyên bố chắc chắn khi chuỗi ĐÃ SỬA đứng vững trên catalog, không
     * phải khi nó "hơn bản gốc". Cổng cũ là so sánh tương đối: đo được "pa ra xê ta
     * môn năm trăm" -> "Par xê ta môn năm trăm" đi qua vì 4 > 3.
     */
    public function test_a_phonetic_rewrite_that_names_nothing_is_not_confident(): void
    {
        $result = $this->normalizer()->normalize(['pa ra xê ta môn năm trăm']);

        if ($result['source'] === 'phonetic') {
            $this->assertFalse(
                $result['confident'],
                "T1 trả '{$result['text']}' — chuỗi này không phải tên sản phẩm nào."
            );
        }

        $this->assertIsString($result['text']);
    }
}
