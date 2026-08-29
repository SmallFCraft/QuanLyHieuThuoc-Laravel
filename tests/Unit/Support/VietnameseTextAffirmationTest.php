<?php

namespace Tests\Unit\Support;

use App\Support\VietnameseText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reading a bare "Có" as a confirmation of whatever was just asked.
 *
 * The triage follow-up round asks danger signs BY NAME ("Anh/chị có sốt, khó thở hoặc
 * đau ngực không?") and stores only the answer, so a real emergency arrives as the
 * single word "Có" — no keyword for any text rule to match. A 20-year-old with COVID
 * reporting dyspnoea at rest and continuous chest pain was told "no danger signs".
 *
 * The obvious fix — folding the question into the haystack — is the failure this whole
 * layer exists to prevent: fold("...đau ngực không?" . " Không") reads as an
 * affirmation of every keyword in it, so a patient who denied everything would be told
 * to call 115. See VietnameseTextNegationTest, which owns that invariant.
 *
 * So the asymmetry here is the mirror of that one: every FALSE below protects a patient
 * from a spurious emergency, and the classifier only ever reads the OPENING words of an
 * answer, because a later "co" is unreliable — "cổ", "cơ" and "cỏ" all fold to it.
 */
class VietnameseTextAffirmationTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function affirmationProvider(): array
    {
        return [
            'bare yes' => ['Có'],
            'yes with particle' => ['Có ạ'],
            'polite lead' => ['Dạ có'],
            'polite lead and particle' => ['Dạ có ạ'],
            'vang' => ['Vâng'],
            'dung roi' => ['Đúng rồi'],
            'phai roi' => ['Phải rồi'],
            'yes with emphasis' => ['Có chứ ạ'],
            'pronoun lead' => ['em có bị'],
            // The real answer from session 55 round 2, to "có sốt, khó thở hay đau ngực,
            // hay cả ba không?"
            'collective with particle' => ['Cả luôn nhé'],
            'collective all three' => ['Cả ba'],
            'collective numeral' => ['Cả 2'],
            'collective tat ca' => ['Tất cả'],
        ];
    }

    #[DataProvider('affirmationProvider')]
    public function test_a_confirmation_is_recognised(string $answer): void
    {
        $this->assertTrue(VietnameseText::isAffirmation($answer), $answer);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function denialProvider(): array
    {
        return [
            'bare no' => ['không'],
            'no with co' => ['Không có'],
            'enumerated denial' => ['không sốt, không nôn, không cứng cổ'],
            'chua' => ['Chưa đo'],
            'chua dung gi' => ['chưa dùng gì'],
            'chang' => ['chẳng có gì'],
            // "co" appears, but a negation particle in the lead ends the scan first.
            'van khong co' => ['vẫn không có'],
        ];
    }

    #[DataProvider('denialProvider')]
    public function test_a_denial_is_never_a_confirmation(string $answer): void
    {
        $this->assertFalse(VietnameseText::isAffirmation($answer), $answer);
    }

    /**
     * Answers that state something without confirming the question. Every one of these
     * is a real answer from session 55 or its UI.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonCommittalProvider(): array
    {
        return [
            'a symptom description' => ['rất mêt mỏi'],
            'an age' => ['20 tuổi'],
            'a duration' => ['mới ngày hôm trước'],
            // Answers an either/or question. It presupposes dyspnoea but confirms
            // nothing lexically, which is what the AI-code tier exists to cover.
            'either or answer' => ['Khi nghỉ và vận động'],
            'a restated symptom' => ['đau ngực liên tục'],
            'empty' => [''],
        ];
    }

    #[DataProvider('nonCommittalProvider')]
    public function test_a_non_committal_answer_is_not_a_confirmation(string $answer): void
    {
        $this->assertFalse(VietnameseText::isAffirmation($answer), $answer);
    }

    /**
     * The reason only the lead is read: these all contain a word that folds to "co".
     *
     * @return array<string, array{0: string}>
     */
    public static function foldCollisionProvider(): array
    {
        return [
            'cung co is stiff neck' => ['cứng cổ'],
            'dau co is neck pain' => ['đau cổ'],
            'dau co is muscle pain' => ['đau cơ'],
            // "cả" opens it, but an object follows instead of a particle, so this is a
            // statement about the ears, not a yes to our question.
            'collective with an object' => ['cả hai bên tai đều ổn'],
        ];
    }

    #[DataProvider('foldCollisionProvider')]
    public function test_a_fold_collision_does_not_confirm(string $answer): void
    {
        $this->assertFalse(VietnameseText::isAffirmation($answer), $answer);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function restrictiveProvider(): array
    {
        return [
            'thoi narrows the yes' => ['có ho thôi', true],
            'chi narrows the yes' => ['có, chỉ ho nhẹ', true],
            'moi narrows the yes' => ['mới bị hôm nay', true],
            'bare yes is not narrowed' => ['Có', false],
            'collective yes is not narrowed' => ['Cả luôn nhé', false],
        ];
    }

    #[DataProvider('restrictiveProvider')]
    public function test_a_narrowed_yes_is_detected(string $answer, bool $expected): void
    {
        $this->assertSame($expected, VietnameseText::isRestrictive($answer), $answer);
    }

    /**
     * A negation reaching forward from the yes-word retracts it: "Vâng, em không có gì"
     * is a denial, where the yes is politeness and the negation is the actual answer.
     *
     * A CONTRAST marker breaks that reach. "Có, nhưng không khó thở" is a partial
     * confirmation: the yes stands, and "nhưng" says the negation applies to something
     * else, so the per-symptom veto drops only dyspnoea and keeps the chest pain. Getting
     * this backwards either escalates a denial or discards a real emergency; both were
     * measured while building this.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function retractionProvider(): array
    {
        return [
            'bare retraction' => ['Vâng, không có', false],
            'retraction behind pronouns' => ['Vâng, em không có triệu chứng nào cả', false],
            'stacked politeness' => ['Dạ vâng, em không bị gì', false],
            'yes-particle then negation' => ['Đúng rồi, em không bị mấy cái đó', false],
            'adjacent negation' => ['Có không', false],
            // The contrast marker keeps the yes alive.
            'contrast keeps the yes' => ['Có, nhưng không khó thở', true],
            'plain yes' => ['Có', true],
        ];
    }

    #[DataProvider('retractionProvider')]
    public function test_a_retracted_yes_is_not_an_affirmation(string $answer, bool $expected): void
    {
        $this->assertSame($expected, VietnameseText::isAffirmation($answer), $answer);
    }

    /**
     * Answers that read as a yes word-by-word but are not one. Every case was measured
     * escalating a healthy patient to emergency before the guard existed.
     *
     * @return array<string, array{0: string}>
     */
    public static function falseYesProvider(): array
    {
        return [
            // Opens with "có" and denies everything after it.
            'co gi dau idiom' => ['Có gì đâu ạ'],
            'reassurance' => ['Vâng em khỏe, không sao'],
            'binh thuong' => ['Dạ bình thường ạ'],
            // The sign is over, and a red flag describes a current state.
            'resolved' => ['Có đỡ rồi ạ'],
            'resolved explicitly' => ['Dạ có, hết rồi ạ'],
            'no longer present' => ['Có hồi hôm qua, nay không còn'],
            // A history question: "trước đây anh/chị từng bị... chưa?"
            'years ago' => ['Có, hồi 5 năm trước'],
            'as a child' => ['Có, hồi nhỏ'],
            // "dùng" and "đúng" both fold to "dung", so a medication answer read as a yes.
            'dung as a verb' => ['Đã dùng thuốc nhưng không đỡ'],
            'dung paracetamol' => ['Đã dùng paracetamol'],
        ];
    }

    #[DataProvider('falseYesProvider')]
    public function test_an_answer_that_only_looks_like_a_yes_is_rejected(string $answer): void
    {
        $this->assertFalse(VietnameseText::isAffirmation($answer), $answer);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function denialClassifierProvider(): array
    {
        return [
            'bare no' => ['Không', true],
            'politeness then no' => ['Dạ không ạ', true],
            'no with co' => ['Không có', true],
            'chua' => ['Chưa đo', true],
            'chua dung gi' => ['chưa dùng gì', true],
            'yes is not a denial' => ['Có', false],
            'collective yes is not a denial' => ['Cả luôn nhé', false],
            // Neither. This one matters most: it is the answer the AI-code tier exists to
            // catch, so counting it as a denial would veto a real emergency.
            'either or answer' => ['Khi nghỉ và vận động', false],
            'a duration' => ['20 tuổi', false],
            'a symptom description' => ['rất mêt mỏi', false],
            // A late negation is not a denial of the question: the answer opens with the
            // sign it affirms.
            'late negation' => ['đau ngực liên tục, không đỡ', false],
        ];
    }

    #[DataProvider('denialClassifierProvider')]
    public function test_an_explicit_denial_is_recognised(string $answer, bool $expected): void
    {
        $this->assertSame($expected, VietnameseText::isDenial($answer), $answer);
    }
}
