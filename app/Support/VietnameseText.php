<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Accent-free folding for Vietnamese free text.
 *
 * Shared because the chatbot's intent detection and the triage rules engine must
 * agree on what "sot cao" means — if they fold differently, the same message is
 * classified one way in chat and another way in the wizard.
 */
final class VietnameseText
{
    /**
     * Particles that cancel a symptom mention that follows them.
     *
     * Deliberately excludes "hong": it folds together with "họng" (throat), so
     * treating it as a negation would silence every sore-throat alias.
     */
    private const NEGATIONS = ['khong', 'ko', 'chua', 'chang'];

    /**
     * Light words allowed to sit between the negation and the symptom, so
     * "không bị sốt", "không có đau ngực" and "không hề có đau đầu" are all
     * recognised as denials.
     *
     * "cam" is excluded on purpose: "cảm" folds to it and is a symptom (cold/flu),
     * not a particle.
     */
    private const NEGATION_BRIDGES = ['bi', 'co', 'thay', 'con', 'he'];

    /** How many bridge words a negation may reach across. */
    private const NEGATION_REACH = 2;

    /** Bare confirmations, trusted only at the very start of an answer. */
    private const AFFIRMATIONS = [
        'co', 'co roi', 'co a', 'dung', 'dung roi', 'vang', 'phai', 'phai roi', 'bi roi', 'ok',
    ];

    /**
     * Yes-words that are also ordinary verbs, so they only count when nothing substantive
     * follows: "đúng rồi" is a yes, "đã dùng thuốc" is not — both fold to start with
     * "dung". Measured: "Đã dùng thuốc nhưng không đỡ" read as a confirmation.
     */
    private const AMBIGUOUS_AFFIRMATIONS = ['dung', 'phai', 'ok'];

    /**
     * Collective yeses, valid alone or before a final particle only: "cả luôn nhé" is a
     * yes, "cả hai bên tai đều ổn" is not.
     */
    private const COLLECTIVE_AFFIRMATIONS = ['ca luon', 'ca ba', 'ca hai', 'ca 2', 'ca 3', 'tat ca'];

    private const AFFIRMATION_PARTICLES = ['nhe', 'a', 'luon', 'roi', 'day', 'do', 'ma', 'nha', 'ah'];

    /** Politeness and pronouns a confirmation may hide behind: "dạ có ạ", "em có bị". */
    private const AFFIRMATION_LEAD = [
        'da', 'vang', 'thua', 'em', 'anh', 'chi', 'toi', 'minh', 'chau', 'bac',
    ];

    /**
     * Words a retracting negation may hide behind: "Vâng, em không có gì".
     *
     * Politeness, pronouns and the particles that trail a yes ("đúng rồi, em không bị"),
     * scanned forward from the yes-word rather than backward from a symptom.
     *
     * Contrast and topic markers are deliberately ABSENT — "nhưng", "mà", "thì". They mean
     * the negation applies to something OTHER than what the yes covered, so "Có, nhưng
     * không khó thở" is a partial confirmation: the yes stands, and the per-symptom veto
     * drops only dyspnoea. Treating those as bridges discarded a real chest-pain
     * emergency.
     */
    private const RETRACTION_BRIDGES = [
        'da', 'vang', 'thua', 'em', 'anh', 'chi', 'toi', 'minh', 'chau', 'bac',
        'la', 'a', 'ho', 'hien', 'gio', 'nay', 'gi', 'nao', 'cai',
        'roi', 'luon', 'nhe', 'day', 'do', 'nha', 'ah',
    ];

    /** How far past a yes-word a retracting negation may sit. */
    private const RETRACTION_REACH = 4;

    /**
     * Idioms that read as a yes word-by-word but mean "nothing at all".
     *
     * "Có gì đâu" is the clearest: it opens with "có" and denies everything after it.
     * "Không sao" and "khỏe" are the same move from the other side — a reassurance, not a
     * confirmation of the danger signs we asked about.
     */
    private const DENIAL_IDIOMS = [
        'co gi dau', 'co sao dau', 'dau co', 'chang co gi', 'khong sao', 'binh thuong',
    ];

    /**
     * The sign existed but does not now: "Có hồi hôm qua, nay không còn", "Có đỡ rồi".
     *
     * A red flag describes a current state, so a resolved one must not escalate.
     */
    private const RESOLVED_MARKERS = ['het roi', 'do roi', 'khoi roi', 'khong con', 'da het', 'da khoi'];

    /**
     * The sign belongs to the past, so a "yes" to a history question is not a red flag:
     * "Có, hồi 5 năm trước" answers "trước đây anh/chị từng bị... chưa?".
     *
     * "hom truoc" and "tuan truoc" are deliberately absent — the day before yesterday is
     * a current episode, and that is the session this whole fix exists for.
     */
    private const PAST_MARKERS = [
        'nam truoc', 'nam ngoai', 'thang truoc', 'hoi nho', 'hoi be', 'ngay xua',
        'truoc day', 'truoc kia', 'luc nho',
    ];

    /** Markers that narrow a yes to part of what was asked: "có ho thôi". */
    private const RESTRICTIVE = ['thoi', 'chi', 'moi', 'duy nhat'];

    /**
     * Intensity words a triage question uses to build a grading scale.
     *
     * Only meaningful in bulk — see offersSeverityScale().
     */
    private const SEVERITY_SCALE = ['nhe', 'vua', 'du doi', 'nang', 'trung binh', 'it', 'nhieu'];

    /** How many scale words a question must offer before it counts as a menu. */
    private const SEVERITY_SCALE_MINIMUM = 2;

    /**
     * "Sốt cao 39 độ!" -> "sot cao 39 do"
     */
    public static function fold(string $text): string
    {
        $normalized = Str::lower(Str::ascii(trim($text)));
        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * Whole-word match against already-folded text, so a short alias like "sot"
     * cannot fire inside "sotret".
     */
    public static function containsWord(string $foldedHaystack, string $rawNeedle): bool
    {
        $needle = self::fold($rawNeedle);

        if ($needle === '' || $foldedHaystack === '') {
            return false;
        }

        return preg_match(
            '/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/',
            $foldedHaystack
        ) === 1;
    }

    /**
     * The text mentions this symptom as something the patient HAS, not as something
     * they denied.
     *
     * Necessary because the triage follow-up asks about danger signs by name ("anh/chị
     * có kèm sốt, nôn hay cứng cổ không ạ?"), so the natural answer — "không sốt,
     * không nôn, không cứng cổ" — contains every keyword. Plain containsWord() read
     * that as meningitis and told the patient to call 115.
     *
     * Kept deliberately tight, because failing the other way is far worse: a negation
     * only counts when it is adjacent, reaching across at most two light verbs. So
     * "không chịu được cơn đau ngực" is still a real emergency — "không" is too far
     * from "đau ngực", and "chịu được cơn" are not bridge words.
     */
    public static function mentionsAffirmatively(string $foldedHaystack, string $rawNeedle): bool
    {
        $needle = self::fold($rawNeedle);

        if ($needle === '' || $foldedHaystack === '') {
            return false;
        }

        $found = preg_match_all(
            '/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/',
            $foldedHaystack,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if ($found === 0 || $found === false) {
            return false;
        }

        // Some keywords are built around a negation on purpose ("khong do", "sot khong
        // ha", "an khong tieu"). Those are affirmed by that very wording.
        if (self::startsWithNegation($needle)) {
            return true;
        }

        // Any single affirmed mention is enough: "không sốt nhưng giờ sốt cao" is a fever.
        foreach ($matches[0] as [, $offset]) {
            if (!self::isDeniedBefore($foldedHaystack, (int) $offset)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The answer confirms whatever the question asked ("Có", "Dạ có ạ", "Cả luôn nhé").
     *
     * Only the opening words count. A later "co" is unreliable: "cổ", "cơ" and "cỏ" all
     * fold to it, so "cứng cổ" would read as a yes. Any negation particle in the lead
     * ends it immediately, which is what keeps "không có" and "chưa đo" out.
     *
     * Exists because a follow-up round asks danger signs BY NAME and stores only the
     * answer, so a bare "Có" carries no keyword for any text rule to match.
     *
     * Four ways a word-by-word yes is rejected, each measured against a real answer:
     * a retracting negation ("Vâng, em không có gì"), a denial idiom ("Có gì đâu ạ"), a
     * resolved or past sign ("Có đỡ rồi ạ", "Có, hồi 5 năm trước"), and an ambiguous
     * yes-word used as a verb ("Đã dùng thuốc nhưng không đỡ" — "dùng" and "đúng" both
     * fold to "dung").
     *
     * What it deliberately cannot do is attribute. "Có, bố em bị" confirms the sign for
     * somebody else, and this reads it as a yes. Escalating a third party's chest pain to
     * a pharmacist review is the safe direction to be wrong, and the pre-existing text
     * path has the same limitation.
     */
    public static function isAffirmation(string $rawAnswer): bool
    {
        $folded = self::fold($rawAnswer);

        if (self::matchesAny($folded, self::DENIAL_IDIOMS)
            || self::matchesAny($folded, self::RESOLVED_MARKERS)
            || self::matchesAny($folded, self::PAST_MARKERS)) {
            return false;
        }

        $words = preg_split('/\s+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $i => $word) {
            if (in_array($word, self::NEGATIONS, true)) {
                return false;
            }

            $rest = array_slice($words, $i);
            $tail = implode(' ', $rest);

            foreach (self::AFFIRMATIONS as $yes) {
                $exact = $tail === $yes;

                if (!$exact && !str_starts_with($tail, $yes . ' ')) {
                    continue;
                }

                // "đúng rồi" is a yes; "đã dùng thuốc" is not, and both open with "dung".
                if (!$exact && in_array($yes, self::AMBIGUOUS_AFFIRMATIONS, true)) {
                    $next = $rest[count(explode(' ', $yes))] ?? null;

                    if ($next !== null && !in_array($next, self::AFFIRMATION_PARTICLES, true)) {
                        return false;
                    }
                }

                return !self::isRetractedAt($rest, $yes);
            }

            foreach (self::COLLECTIVE_AFFIRMATIONS as $yes) {
                if ($tail === $yes) {
                    return true;
                }

                if (str_starts_with($tail, $yes . ' ')) {
                    // "cả luôn nhé" is a yes; "cả hai bên tai đều ổn" names an object and
                    // is a statement about it, not a confirmation of our question.
                    $next = $rest[count(explode(' ', $yes))] ?? null;

                    return $next === null || in_array($next, self::AFFIRMATION_PARTICLES, true);
                }
            }

            if (!in_array($word, self::AFFIRMATION_LEAD, true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * A negation follows the yes-word closely enough to retract it.
     *
     * "Vâng, em không có gì" is a denial — the yes is politeness and the negation is the
     * answer. "Có, nhưng không khó thở" is not: "nhung" is a contrast marker, not a
     * bridge, so the yes stands and the negation scopes onto the one sign it names, which
     * the per-symptom veto then drops.
     *
     * Reach is 4 rather than the backward NEGATION_REACH of 2 because politeness stacks
     * in front of the negation ("dạ vâng, em không bị gì"), and it only crosses words
     * that carry no clinical content.
     *
     * @param array<int, string> $words Folded words from the yes-word onward.
     */
    private static function isRetractedAt(array $words, string $foldedYes): bool
    {
        $after = array_slice($words, count(explode(' ', $foldedYes)));

        foreach (array_slice($after, 0, self::RETRACTION_REACH) as $word) {
            if (in_array($word, self::NEGATIONS, true)) {
                return true;
            }

            if (!in_array($word, self::RETRACTION_BRIDGES, true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $phrases
     */
    private static function matchesAny(string $foldedText, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (self::containsWord($foldedText, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The answer denies whatever the question asked ("Không", "Dạ không ạ", "Chưa đo").
     *
     * The mirror of isAffirmation(), read the same way and for the same reason: only the
     * opening words count. Needed because a bare "Không" denies the question's danger
     * signs without naming any of them, so a keyword-based veto has nothing to see — and
     * the AI extractor, which reads the question too, may still return their codes.
     *
     * Non-committal answers are neither ("Khi nghỉ và vận động", "20 tuổi"): they must not
     * count as denials, or a real emergency that only the extractor understood is lost.
     */
    public static function isDenial(string $rawAnswer): bool
    {
        $words = preg_split('/\s+/', self::fold($rawAnswer), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if (in_array($word, self::NEGATIONS, true)) {
                return true;
            }

            // Politeness may precede the negation; anything substantive ends the scan.
            if (!in_array($word, self::AFFIRMATION_LEAD, true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * The yes covers only part of what was asked: "có ho thôi", "có, chỉ ho nhẹ".
     *
     * A multi-sign question ("có sốt, khó thở hoặc đau ngực không?") answered with a
     * narrowed yes must not be read as confirming all of them.
     */
    public static function isRestrictive(string $rawAnswer): bool
    {
        $folded = self::fold($rawAnswer);

        foreach (self::RESTRICTIVE as $marker) {
            if (self::containsWord($folded, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The question hands the patient a grading scale to pick from ("nhẹ, vừa hay dữ
     * dội?"), rather than asking whether a specific danger sign is present.
     *
     * Requires several intensity words on purpose. One is not a scale: "có đau ngực dữ
     * dội không?" names a danger sign and must keep its full weight.
     */
    public static function offersSeverityScale(string $rawQuestion): bool
    {
        $folded = self::fold($rawQuestion);
        $found = 0;

        foreach (self::SEVERITY_SCALE as $term) {
            if (self::containsWord($folded, $term)) {
                $found++;
            }
        }

        return $found >= self::SEVERITY_SCALE_MINIMUM;
    }

    /**
     * Every word of the needle already appears in the text, so an answer containing the
     * needle is repeating vocabulary the question supplied.
     *
     * Word-wise rather than whole-phrase, because the phrase rarely survives the
     * question's own grammar: "đau mức độ nhẹ, vừa hay dữ dội" never contains the
     * contiguous "dau du doi", yet it offers every word of it.
     */
    public static function echoesEveryWord(string $rawText, string $rawNeedle): bool
    {
        $folded = self::fold($rawText);
        $words = preg_split('/\s+/', self::fold($rawNeedle), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === [] || $folded === '') {
            return false;
        }

        foreach ($words as $word) {
            if (!self::containsWord($folded, $word)) {
                return false;
            }
        }

        return true;
    }

    private static function startsWithNegation(string $foldedNeedle): bool
    {
        foreach (self::NEGATIONS as $negation) {
            if (self::containsWord($foldedNeedle, $negation)) {
                return true;
            }
        }

        return false;
    }

    private static function isDeniedBefore(string $foldedHaystack, int $offset): bool
    {
        $words = preg_split(
            '/\s+/',
            trim(substr($foldedHaystack, 0, $offset)),
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];

        for ($i = count($words) - 1, $crossed = 0; $i >= 0 && $crossed <= self::NEGATION_REACH; $i--, $crossed++) {
            if (in_array($words[$i], self::NEGATIONS, true)) {
                return true;
            }

            // Anything other than a light verb ends the negation's reach.
            if (!in_array($words[$i], self::NEGATION_BRIDGES, true)) {
                return false;
            }
        }

        return false;
    }
}
