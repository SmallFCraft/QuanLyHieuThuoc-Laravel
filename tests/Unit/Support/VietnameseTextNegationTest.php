<?php

namespace Tests\Unit\Support;

use App\Support\VietnameseText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Negation-aware symptom matching.
 *
 * This exists because the triage follow-up round asks about danger signs BY NAME
 * ("anh/chị có kèm sốt, nôn hay cứng cổ không ạ?"). The natural Vietnamese answer,
 * "không sốt, không nôn, không cứng cổ", contains every keyword, and plain
 * word-boundary matching read it as meningitis.
 *
 * The asymmetry is deliberate and load-bearing: missing a real red flag is far worse
 * than firing a spurious one, so the negation window is tight and every test below
 * that asserts a TRUE is protecting against over-eager negation handling.
 */
class VietnameseTextNegationTest extends TestCase
{
    protected function affirms(string $sentence, string $needle): bool
    {
        return VietnameseText::mentionsAffirmatively(VietnameseText::fold($sentence), $needle);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function deniedProvider(): array
    {
        return [
            'bare negation' => ['không sốt', 'sot'],
            'negation with bi' => ['không bị sốt', 'sot'],
            'negation with co' => ['không có đau ngực', 'dau nguc'],
            'negation with thay' => ['không thấy khó thở', 'kho tho'],
            'short ko' => ['ko sốt', 'sot'],
            'chua' => ['chưa sốt', 'sot'],
            'multi-phrase answer' => ['không sốt, không nôn, không cứng cổ', 'cung co'],
            'mid-sentence' => ['cháu ăn uống bình thường, không co giật', 'co giat'],
            'two bridges' => ['không hề có đau đầu dữ dội', 'dau dau du doi'],
        ];
    }

    #[DataProvider('deniedProvider')]
    public function test_a_denied_symptom_is_not_a_mention(string $sentence, string $needle): void
    {
        $this->assertFalse($this->affirms($sentence, $needle), $sentence);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function affirmedProvider(): array
    {
        return [
            'plain statement' => ['tôi bị sốt cao', 'sot'],
            'no negation at all' => ['đau ngực dữ dội, khó thở', 'dau nguc'],
            // "không" belongs to a different clause: the pain IS present.
            'negation far away' => ['tôi không chịu được cơn đau ngực này', 'dau nguc'],
            'negation on another symptom' => ['không sốt nhưng đau ngực dữ dội', 'dau nguc'],
            // Denied first, then reported: the later mention wins.
            'denied then affirmed' => ['lúc đầu không sốt, giờ sốt 39 độ', 'sot'],
            'negation after the symptom' => ['sốt cao, uống thuốc không đỡ', 'sot'],
            // The keyword is itself built around a negation, so this wording affirms it.
            'keyword contains negation' => ['uống thuốc mà không đỡ', 'khong do'],
            'fever that will not come down' => ['sốt không hạ dù đã uống hạ sốt', 'sot khong ha'],
            'constipation phrased as negation' => ['đi ngoài mà ỉa không ra', 'ia khong ra'],
        ];
    }

    #[DataProvider('affirmedProvider')]
    public function test_a_real_mention_still_counts(string $sentence, string $needle): void
    {
        $this->assertTrue($this->affirms($sentence, $needle), $sentence);
    }

    public function test_throat_aliases_survive_the_negation_check(): void
    {
        // "họng" folds to "hong", which is also a negation particle in some dialects.
        // Treating it as one would silence every sore-throat alias.
        $this->assertTrue($this->affirms('tôi bị đau họng', 'dau hong'));
        $this->assertTrue($this->affirms('viêm họng mấy ngày rồi', 'viem hong'));
    }

    public function test_word_boundaries_are_still_respected(): void
    {
        $this->assertFalse($this->affirms('tôi bị sốt rét', 'so'));
        $this->assertTrue($this->affirms('tôi bị sốt rét', 'sot ret'));
    }

    public function test_empty_input_is_never_a_mention(): void
    {
        $this->assertFalse($this->affirms('', 'sot'));
        $this->assertFalse($this->affirms('tôi bị sốt', ''));
    }
}
