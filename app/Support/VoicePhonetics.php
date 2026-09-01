<?php

namespace App\Support;

/**
 * Phonetic keys for Vietnamese voice search.
 *
 * Region-collapsing on purpose: a Southern speaker's "thuấc hạ sốc" and the
 * catalog's "thuốc hạ sốt" have to produce the same key, or voice search only
 * works for the accent the browser's recogniser happened to be tuned on.
 */
final class VoicePhonetics
{
    /** Flat keys longer than this never take part in near-matching. */
    private const MAX_BLOCK_LENGTH = 24;

    private const BLOCK_LENGTH = 2;

    /**
     * A syllable this short carries the whole distinguishing sense of a phrase.
     * Measured: any tolerance that lets one of them drift merges "vitamin C"
     * with "vitamin D".
     */
    private const ATOMIC_SYLLABLE = 2;

    /**
     * Onsets, longest alternative first. Each anchors at the start and replaces
     * once, so an uppercase marker already written blocks the later patterns.
     */
    private const ONSETS = [
        '/^(?:tr|ch)/' => 'C',
        '/^(?:s|x)/' => 'S',
        '/^(?:gi|d|v|r)/' => 'D',
        '/^(?:n|l)/' => 'L',
        '/^(?:qu|q|k|c)/' => 'K',
        '/^(?:ph|f)/' => 'F',
    ];

    /**
     * Diphthongs. Must run before the codas — see syllable().
     *
     * Order inside the table counts too, for the same reason: `uo` would eat the
     * "uo" of "uoi" and leave an `i` that no rule claims, so "muối" keyed `mUi`
     * while "mói" keyed `mO` and the pair stopped matching. Longest rime first.
     */
    private const RIMES = [
        '/iee|ie|ye|ia/' => 'I',
        '/uoi|oi/' => 'O',
        '/uoo|uo|ua/' => 'U',
    ];

    private const CODAS = [
        '/(?:nh|ng|n|m)$/' => 'N',
        '/(?:ch|c|t|k|p)$/' => 'T',
    ];

    /**
     * The single-letter half of ONSETS, as a plain translation table — used by
     * coarse() to re-apply the classes away from a word start.
     */
    private const MID_WORD_ONSETS = [
        's' => 'S', 'x' => 'S',
        'd' => 'D', 'v' => 'D', 'r' => 'D',
        'n' => 'L', 'l' => 'L',
        'k' => 'K', 'c' => 'K',
        'f' => 'F',
    ];

    private const VOWELS = [
        'au' => 'o', 'oo' => 'o', 'ee' => 'e', 'uu' => 'u', 'aa' => 'a', 'y' => 'i',
    ];

    public static function key(string $text): string
    {
        $words = preg_split('/\s+/', VietnameseText::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map([self::class, 'syllable'], $words));
    }

    public static function syllable(string $foldedWord): string
    {
        $syllable = $foldedWord;

        foreach (self::ONSETS as $pattern => $marker) {
            $syllable = preg_replace($pattern, $marker, $syllable, 1) ?? $syllable;
        }

        // Mid-word x reads as s: "canxi" and "can si" are one word spoken two ways.
        $syllable = str_replace('x', 'S', $syllable);

        foreach (self::RIMES as $pattern => $marker) {
            $syllable = preg_replace($pattern, $marker, $syllable) ?? $syllable;
        }

        // After the rimes, never before: `t$ => T` would otherwise eat the final
        // consonant of "uot", leaving "uo" unable to match its own rime.
        foreach (self::CODAS as $pattern => $marker) {
            $syllable = preg_replace($pattern, $marker, $syllable) ?? $syllable;
        }

        $syllable = strtr($syllable, self::VOWELS);

        // Letters only: a doubled letter is one sound ("oo" -> "o"), but a doubled
        // digit is a dose. Collapsing digits made "500" and "50" the same key, and
        // both are short enough that within() then accepts them as one product.
        return preg_replace('/([^0-9])\1+/', '$1', $syllable) ?? $syllable;
    }

    public static function blockKey(string $key): ?string
    {
        $flat = self::flatten($key);

        if ($flat === '' || strlen($flat) > self::MAX_BLOCK_LENGTH) {
            return null;
        }

        // Case-folded on purpose, unlike phonetic_key: this is the coarse SQL
        // filter, within() is the exact one. Measured: "tiu" keys `tiu` and
        // "tiêu" keys `tIu`, so a case-sensitive prefix put them in blocks 'ti'
        // and 'tI' and the orWhereIn never loaded a candidate within() accepts.
        // Folding only widens the candidate set; within() still rejects.
        return strtolower(substr($flat, 0, self::BLOCK_LENGTH));
    }

    /**
     * Two keys describe the same spoken phrase.
     *
     * Compared syllable by syllable rather than as one string: a single distance
     * budget over the whole key merged "vitamin C" with "vitamin D" at every
     * threshold measured, and in a pharmacy that is the wrong way to be wrong.
     */
    public static function within(string $a, string $b): bool
    {
        if ($a === $b) {
            return $a !== '';
        }

        if ($a === '' || $b === '') {
            return false;
        }

        $left = explode(' ', $a);
        $right = explode(' ', $b);

        if (count($left) === count($right)) {
            $distance = 0;

            foreach ($left as $i => $syllable) {
                $other = $right[$i];

                if ($syllable === $other) {
                    continue;
                }

                if (strlen($syllable) <= self::ATOMIC_SYLLABLE
                    || strlen($other) <= self::ATOMIC_SYLLABLE) {
                    return false;
                }

                // A digit is a quantity, not a pronunciation — no accent reads
                // 500 as 50. Measured: "500mg" vs "50mg" is lev 1 against a
                // budget of max(1, 5/4) = 1, so the two doses merged. The unit
                // leaks the same way: "500mg" vs "500ml" is also lev 1.
                if (self::digits($syllable) !== '' || self::digits($other) !== '') {
                    return false;
                }

                $distance += levenshtein($syllable, $other);
            }

            return $distance <= max(1, intdiv(strlen(self::flatten($a)), 4));
        }

        // Different syllable counts: the speaker broke one word into several
        // ("pa ra xe ta mon"), so only the flattened key is comparable.
        $flatA = self::flatten($a);

        // Splitting a syllable still may not cross doses: "500mg" read as
        // "50 mg" is lev 1 on the flat key. Only enforced when both sides carry
        // digits — "omega 3" vs "ô mê ga ba" spells the digit out as a word,
        // and that pair has to keep matching.
        $digitsA = self::digits($a);
        $digitsB = self::digits($b);

        if ($digitsA !== '' && $digitsB !== '' && $digitsA !== $digitsB) {
            return false;
        }

        if (levenshtein($flatA, self::flatten($b)) <= max(1, intdiv(strlen($flatA), 3))) {
            return true;
        }

        // Second pass on a coarser form. syllable() applies the §4.1 letter classes
        // only at a word start, so a name read syllable by syllable collects them
        // once per syllable while the joined catalog name collects them once in
        // total: "pa na đôn" keys `pa La DoN` against `panadol`, lev 3 on a budget
        // of 2. Re-applying the same classes at every position lines the two up and
        // recovers "sa lon pát", "ni giô ran" and "côn la gien" as well.
        //
        // Widening a distance budget cannot do this job: the closest distinct pair
        // that must stay apart ("vitamin e" / "betadine") sits at lev 4 over 8,
        // the same ratio as the brand readings that must match, so no threshold
        // separates them. Measured on the coarse form instead: zero new merges
        // across the 26 distinct product names, and one new pair over the whole
        // built index — "Naturals" with "Nature's", one product line.
        $coarseA = self::coarse($flatA);

        return levenshtein($coarseA, self::coarse(self::flatten($b)))
            <= max(1, intdiv(strlen($coarseA), 3));
    }

    public static function distance(string $a, string $b): int
    {
        return levenshtein(self::flatten($a), self::flatten($b));
    }

    private static function digits(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    /**
     * The §4.1 letter classes re-applied at every position, not only at a word
     * start. Only the lowercase leftovers move: an uppercase marker is a class
     * syllable() already decided, and rewriting those would fold `C` into `K`
     * and merge classes the table deliberately keeps apart.
     *
     * Comparison-only — never stored. `phonetic_key` and `block_key` keep their
     * own shape, so this needs no index rebuild.
     */
    private static function coarse(string $flatKey): string
    {
        $classed = preg_replace(
            ['/tr|ch/', '/gi/', '/ph/', '/qu/'],
            ['C', 'D', 'F', 'K'],
            $flatKey
        ) ?? $flatKey;

        $classed = strtr($classed, self::MID_WORD_ONSETS);

        return preg_replace('/([^0-9])\1+/', '$1', $classed) ?? $classed;
    }

    private static function flatten(string $key): string
    {
        return str_replace(' ', '', $key);
    }
}
