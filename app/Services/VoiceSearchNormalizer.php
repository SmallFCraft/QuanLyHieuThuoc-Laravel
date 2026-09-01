<?php

namespace App\Services;

use App\Models\Product;
use App\Models\VoicePhonetic;
use App\Support\VietnameseText;
use App\Support\VoicePhonetics;
use Illuminate\Support\Collection;

/**
 * Chuẩn hoá chuỗi Web Speech nghe được thành một từ khoá tìm kiếm dùng được.
 *
 * Bốn tầng, rẻ trước đắt sau. Một câu khớp được ở tầng rẻ không bao giờ đi tới
 * tầng đắt — đó là điều giữ cho tính năng chịu được ngàn người dùng cùng lúc, và
 * là bất biến mà VoiceSearchNormalizerTest khoá lại.
 *
 * Nhận diện chạy trong browser: service này chỉ thấy text. Đo 2026-09-01, cả hai
 * AI provider của repo đều không có STT tiếng Việt dùng được — một model trả "pm",
 * một model bịa tên thuốc không tồn tại.
 */
class VoiceSearchNormalizer
{
    public const SOURCE_EXACT = 'exact';
    public const SOURCE_PHONETIC = 'phonetic';
    public const SOURCE_AI = 'ai';
    public const SOURCE_RAW = 'raw';

    /** Cửa sổ dài nhất xét tới: tên thuốc trong catalog hầu hết 1-3 âm tiết. */
    private const MAX_WINDOW_WORDS = 3;

    /** Điểm cộng theo nguồn của cụm, để tên sản phẩm thắng một token trùng. */
    private const KIND_BONUS = ['product' => 20, 'category' => 10, 'token' => 0];

    /**
     * @param array<int, string> $alternatives Web Speech trả tối đa 3, tốt nhất trước.
     * @return array{text: string, heard: string, source: string, confident: bool}
     */
    public function normalize(array $alternatives): array
    {
        $alternatives = $this->clean($alternatives);
        $heard = $alternatives[0] ?? '';

        if ($alternatives === []) {
            return $this->result('', '', self::SOURCE_RAW, false);
        }

        foreach ($alternatives as $alternative) {
            if ($this->catalogHas($alternative)) {
                return $this->result($alternative, $heard, self::SOURCE_EXACT, true);
            }
        }

        $phonetic = $this->matchPhonetically($alternatives);

        if ($phonetic !== null) {
            return $this->result($phonetic, $heard, self::SOURCE_PHONETIC, true);
        }

        return $this->result($heard, $heard, self::SOURCE_RAW, false);
    }

    /**
     * @param array<int, string> $alternatives
     * @return array<int, string>
     */
    private function clean(array $alternatives): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $alternatives
        ), fn (string $value) => $value !== ''));
    }

    /**
     * Cụm này tìm ra sản phẩm nào không.
     *
     * exists() chứ không get(): tầng này chỉ cần biết CÓ hay KHÔNG, còn danh sách
     * sản phẩm là việc của trang tìm kiếm mà khách sẽ tới sau.
     */
    private function catalogHas(string $term): bool
    {
        if (Product::searchWords($term) === []) {
            return false;
        }

        return Product::notExpired()->storefrontSearch($term)->exists();
    }

    /**
     * Thay đoạn nghe lệch bằng cụm trong catalog có khoá phiên âm tương đương.
     */
    private function matchPhonetically(array $alternatives): ?string
    {
        $windows = $this->windows($alternatives);

        if ($windows === []) {
            return null;
        }

        $candidates = $this->lookup($windows);

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = null;

        foreach ($windows as $window) {
            foreach ($candidates as $candidate) {
                if (! VoicePhonetics::within($window['key'], (string) $candidate->phonetic_key)) {
                    continue;
                }

                $score = $this->score($window, $candidate);

                if ($best === null || $score > $best['score']) {
                    $best = ['score' => $score, 'window' => $window, 'term' => (string) $candidate->term];
                }
            }
        }

        if ($best === null) {
            return null;
        }

        // Chỉ đoạn nghe lệch bị thay; phần còn lại của câu giữ nguyên, vì khách có
        // thể đã nói thêm bối cảnh ("cho em bé") mà catalog không chứa.
        return $this->substitute($best['window'], $best['term']);
    }

    /**
     * Cửa sổ 1-3 từ liền nhau, dài trước ngắn sau, gộp trùng qua mọi alternative.
     *
     * Đo được: 3 alternative x 6 cửa sổ chỉ cho 6-14 khoá khác nhau, vì Web Speech
     * trả các alternative gần giống nhau.
     *
     * @return array<string, array{key: string, text: string, source: string}>
     */
    private function windows(array $alternatives): array
    {
        $cap = max(1, (int) config('search.voice.max_windows', 6));
        $windows = [];

        foreach ($alternatives as $alternative) {
            $words = preg_split('/\s+/', $alternative, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $taken = 0;

            for ($size = self::MAX_WINDOW_WORDS; $size >= 1 && $taken < $cap; $size--) {
                for ($i = 0; $i + $size <= count($words) && $taken < $cap; $i++) {
                    $text = implode(' ', array_slice($words, $i, $size));
                    $key = VoicePhonetics::key($text);

                    $taken++;

                    if ($key === '' || isset($windows[$key])) {
                        continue;
                    }

                    $windows[$key] = ['key' => $key, 'text' => $text, 'source' => $alternative];
                }
            }
        }

        return $windows;
    }

    /**
     * Một truy vấn cho cả tầng: khớp khít bằng phonetic_key, khớp gần bằng
     * block_key. within() là hàm PHP nên bộ lọc thô phải làm trong SQL, không thì
     * mỗi request nạp cả index ra bộ nhớ.
     */
    private function lookup(array $windows): Collection
    {
        $keys = array_keys($windows);
        $blocks = array_values(array_unique(array_filter(
            array_map(fn (array $window) => VoicePhonetics::blockKey($window['key']), $windows)
        )));

        return VoicePhonetic::query()
            ->select('phonetic_key', 'term', 'kind', 'weight')
            ->where(function ($query) use ($keys, $blocks): void {
                $query->whereIn('phonetic_key', $keys);

                if ($blocks !== []) {
                    $query->orWhereIn('block_key', $blocks);
                }
            })
            ->get();
    }

    /**
     * Điểm chỉ xếp thứ tự giữa các ứng viên within() ĐÃ nhận — không quyết định
     * nhận hay loại. Trộn hai việc đó lại là cách "vitamin C" thành "vitamin D".
     */
    private function score(array $window, VoicePhonetic $candidate): int
    {
        $key = (string) $candidate->phonetic_key;
        $exact = $key === $window['key'];

        return ($exact ? 100 : 100 - VoicePhonetics::distance($window['key'], $key) * 12)
            + (self::KIND_BONUS[$candidate->kind] ?? 0)
            + min((int) $candidate->weight, 20);
    }

    private function substitute(array $window, string $term): string
    {
        $whole = $window['source'];

        if (VietnameseText::fold($whole) === VietnameseText::fold($window['text'])) {
            return $term;
        }

        $replaced = str_ireplace($window['text'], $term, $whole, $count);

        // Cửa sổ được sinh từ chuỗi đã fold nên nó không luôn xuất hiện nguyên văn
        // trong câu gốc ("sốc" vs "sốt"). Không thay được thì dùng cụm trong catalog:
        // nó là phần mang thông tin, phần còn lại chỉ là bối cảnh.
        return $count > 0 ? $replaced : $term;
    }

    /**
     * @return array{text: string, heard: string, source: string, confident: bool}
     */
    private function result(string $text, string $heard, string $source, bool $confident): array
    {
        return [
            'text' => $text,
            'heard' => $heard,
            'source' => $source,
            'confident' => $confident,
        ];
    }
}
