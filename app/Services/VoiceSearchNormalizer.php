<?php

namespace App\Services;

use App\Models\Product;
use App\Models\VoicePhonetic;
use App\Support\VietnameseText;
use App\Support\VoicePhonetics;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /** Cụm mở đầu dài nhất trong LEAD_PHRASES ("tôi muốn tìm" — ba từ). */
    private const MAX_LEAD_WORDS = 3;

    /**
     * Cụm mở đầu mang ý định, không mang từ khoá. Viết có dấu cho người đọc;
     * leadPhrases() fold lại trước khi so.
     *
     * Cố ý KHÔNG có "có", "còn", "bạn có". Chúng là một âm tiết ngắn và fold() gộp
     * chúng với những từ mang nghĩa: fold("còn") = fold("con") = "con", fold("có") =
     * fold("cơ") = fold("cỏ") = "co". Đo được khi thử: "con tôi bị sốt" mất chủ ngữ
     * thành "tôi bị sốt", và tệ hơn, "côn la gien" (collagen đọc rời âm) thành
     * "La gien" — cắt đứt chính tên thuốc. Một cụm mệnh lệnh chỉ đáng bỏ khi nó
     * không thể là một phần của tên hàng.
     */
    private const LEAD_PHRASES = [
        'tìm', 'tìm kiếm', 'tìm giúp tôi', 'tìm cho tôi', 'tìm cho mình',
        'cho tôi', 'cho tôi xem', 'cho mình', 'cho mình xem', 'cho em', 'cho em hỏi',
        'tôi muốn', 'tôi muốn tìm', 'tôi muốn mua', 'tôi muốn xem',
        'tôi cần', 'tôi cần tìm', 'tôi cần mua',
        'mua', 'kiếm', 'tra cứu',
    ];

    /** @var array<string, true>|null */
    private static ?array $leadPhrases = null;

    /** Điểm cộng theo nguồn của cụm, để tên sản phẩm thắng một token trùng. */
    private const KIND_BONUS = ['product' => 20, 'category' => 10, 'token' => 0];

    /** Số cụm ứng viên đưa vào prompt. Nhiều hơn là trả tiền token cho nhiễu. */
    private const AI_CANDIDATES = 40;

    /** Giá trị cache cho một câu mà AI cũng không giúp được. */
    private const AI_NO_HELP = "\0no-help";

    /**
     * @param array<int, string> $alternatives Web Speech trả tối đa 3, tốt nhất trước.
     * @return array{text: string, heard: string, source: string, confident: bool}
     */
    public function normalize(array $alternatives): array
    {
        // heard là chuỗi khách nói y nguyên — nút "Hoàn tác" trả lại đúng chữ đó, kể
        // cả dấu câu Chrome tự thêm và cụm mệnh lệnh. Chỉ chuỗi đi vào các tầng so
        // khớp mới được làm sạch.
        $heard = trim((string) ($alternatives[0] ?? ''));
        $alternatives = $this->clean($alternatives);

        if ($alternatives === []) {
            return $this->result('', $heard, self::SOURCE_RAW, false);
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

        $ai = $this->askAi($alternatives);

        if ($ai !== null) {
            return $this->result($ai, $heard, self::SOURCE_AI, false);
        }

        return $this->result($alternatives[0], $heard, self::SOURCE_RAW, false);
    }

    /**
     * @param array<int, string> $alternatives
     * @return array<int, string>
     */
    private function clean(array $alternatives): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => $this->stripLeadPhrase($this->trimPunctuation((string) $value)),
            $alternatives
        ), fn (string $value) => $value !== ''));
    }

    /**
     * Dấu câu ở hai đầu bị cắt; dấu câu giữa câu thì không — "omega-3" và
     * "vitamin B6" là tên hàng, không phải câu có dấu.
     *
     * Chrome vi-VN LUÔN chấm hết câu, nên không cắt là để dấu chấm đi vào ô tìm kiếm,
     * vào `?q=` và vào nhãn "Xem tất cả kết quả cho …". fold() bỏ dấu câu lúc so khớp
     * nhưng chuỗi trả về cho khách thì không đi qua fold().
     */
    private function trimPunctuation(string $value): string
    {
        return preg_replace('/^[\p{P}\s]+|[\p{P}\s]+$/u', '', $value) ?? trim($value);
    }

    /**
     * Bỏ cụm mở đầu mang ý định chứ không mang từ khoá.
     *
     * Đo trên catalog: từ `tim` khớp 81 sản phẩm (tim là cơ quan), `cho` khớp 639,
     * `toi` khớp 87. Nên "Tìm giúp tôi men vi sinh" được T0 tuyên bố exact nhờ chính
     * từ mệnh lệnh rồi dừng — tầng phiên âm không bao giờ chạy để sửa phần khách hỏi.
     *
     * So trên chuỗi đã fold vì khách nói có dấu, và dài trước ngắn sau vì nếu không
     * thì `tim` ăn trước `tim kiem`. Chỉ bỏ khi phần còn lại vẫn là một truy vấn:
     * khách nói đúng một từ "Tìm" thì đó là tất cả những gì ta có.
     */
    private function stripLeadPhrase(string $value): string
    {
        $words = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $phrases = self::leadPhrases();

        // Fold từng từ riêng, không fold cả câu: fold() tách "omega-3" thành hai từ,
        // và một chỉ số lệch thì cắt mất phần khách thật sự hỏi.
        $folded = array_map(fn (string $word) => VietnameseText::fold($word), $words);

        for ($take = min(count($words) - 1, self::MAX_LEAD_WORDS); $take >= 1; $take--) {
            $prefix = trim(implode(' ', array_slice($folded, 0, $take)));

            if (isset($phrases[$prefix])) {
                return implode(' ', array_slice($words, $take));
            }
        }

        return $value;
    }

    /**
     * Khoá là dạng đã fold, nên bảng bên dưới viết có dấu cho người đọc: thêm một cụm
     * mới không cần biết fold() trả ra gì.
     *
     * @return array<string, true>
     */
    private static function leadPhrases(): array
    {
        return self::$leadPhrases ??= array_fill_keys(array_map(
            fn (string $phrase) => VietnameseText::fold($phrase),
            self::LEAD_PHRASES
        ), true);
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
     * Tầng cuối trước khi trả nguyên văn: nhờ AI chọn từ khoá từ danh sách ứng viên.
     *
     * Bốn van chặn, mỗi van có test riêng: chỉ chạy khi hai tầng đầu trượt, cache
     * 24h, một khoá lock cho mỗi câu, và một circuit breaker. Không có chúng thì
     * 1.000 lượt/phút là 1.000 lời gọi/phút vào một provider đo được mất 18-26s.
     */
    private function askAi(array $alternatives): ?string
    {
        if (! (bool) config('search.voice.ai', true) || $this->breakerIsOpen()) {
            return null;
        }

        $cacheKey = 'voice:norm:' . sha1(VietnameseText::fold($alternatives[0]));
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached === self::AI_NO_HELP ? null : $cached;
        }

        // block(3): 100 người nói cùng một câu thì một người gọi, 99 người còn lại
        // chờ rồi đọc cache. Bảng cache_locks đã tồn tại nên đây là khoá thật.
        $lock = Cache::lock($cacheKey . ':lock', 10);

        try {
            $lock->block(3);
        } catch (Throwable) {
            // Không lấy được khoá trong 3s: trả nguyên văn thay vì xếp hàng thêm.
            return null;
        }

        try {
            $fresh = Cache::get($cacheKey);

            if ($fresh !== null) {
                return $fresh === self::AI_NO_HELP ? null : $fresh;
            }

            $answer = $this->verifyAgainstCatalog($this->callAi($alternatives));

            Cache::put(
                $cacheKey,
                $answer ?? self::AI_NO_HELP,
                (int) config('search.voice.cache_ttl', 86400)
            );

            return $answer;
        } catch (Throwable $exception) {
            $this->recordFailure();

            Log::warning('Voice search AI normalisation failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        } finally {
            $lock->release();
        }
    }

    private function callAi(array $alternatives): string
    {
        $response = app(AiProviderService::class)->chat([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($alternatives)],
        ], ['timeout' => (int) config('search.voice.ai_timeout', 8)]);

        return trim((string) ($response['choices'][0]['message']['content'] ?? ''));
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Bạn sửa lỗi nhận diện giọng nói cho ô tìm kiếm của một nhà thuốc Việt Nam.',
            'Khách nói tiếng Việt với giọng Bắc, Trung hoặc Nam nên máy có thể nghe lệch.',
            'Chỉ được chọn hoặc ghép từ danh sách cụm có sẵn, hoặc trả lại nguyên văn.',
            'TUYỆT ĐỐI KHÔNG tự nghĩ ra tên thuốc, hoạt chất hay thương hiệu nào.',
            'Trả về ĐÚNG MỘT DÒNG là từ khoá tìm kiếm, không giải thích, không markdown.',
        ]);
    }

    private function userPrompt(array $alternatives): string
    {
        $candidates = $this->candidatesForPrompt($alternatives);

        return implode("\n", array_filter([
            'Máy nghe được (tốt nhất trước):',
            ...array_map(fn (string $value) => '- ' . $value, $alternatives),
            '',
            $candidates === [] ? null : 'Cụm có trong catalog gần giống nhất:',
            ...array_map(fn (string $value) => '- ' . $value, $candidates),
        ]));
    }

    /**
     * Ứng viên cho prompt là chính những dòng within() đã LOẠI ở T1 — chúng gần về
     * phiên âm nhưng chưa đủ gần để nhận tự động. Nhờ vậy một truy vấn phục vụ cả
     * hai tầng.
     *
     * @return array<int, string>
     */
    private function candidatesForPrompt(array $alternatives): array
    {
        $windows = $this->windows($alternatives);

        if ($windows === []) {
            return [];
        }

        return $this->lookup($windows)
            ->sortByDesc(fn (VoicePhonetic $row) => (self::KIND_BONUS[$row->kind] ?? 0) + min((int) $row->weight, 20))
            ->pluck('term')
            ->unique()
            ->take(self::AI_CANDIDATES)
            ->values()
            ->all();
    }

    /**
     * Luật §2.2 của spec: chuỗi AI trả về chỉ được dùng nếu nó thật sự tìm ra thứ
     * gì trong catalog. Đo được provider trả "parasitome" cho "paracetamol" — trong
     * app nhà thuốc, một tên thuốc bịa không được đi tới ô tìm kiếm.
     */
    private function verifyAgainstCatalog(string $answer): ?string
    {
        $answer = trim(preg_replace('/\s+/', ' ', strip_tags($answer)) ?? '');

        if ($answer === '' || mb_strlen($answer) > 200) {
            return null;
        }

        if ($this->catalogHas($answer)) {
            return $answer;
        }

        $key = VoicePhonetics::key($answer);

        return $key !== '' && VoicePhonetic::where('phonetic_key', $key)->exists() ? $answer : null;
    }

    private function breakerIsOpen(): bool
    {
        return (int) Cache::get('voice:norm:ai_failures', 0)
            >= max(1, (int) config('search.voice.breaker_failures', 5));
    }

    /**
     * Bộ đếm tự hết hạn theo cửa sổ, nên breaker tự đóng lại mà không cần job nào.
     */
    private function recordFailure(): void
    {
        $window = (int) config('search.voice.breaker_window', 300);

        // add() trước rồi increment(): increment() trên khoá chưa tồn tại không đặt
        // được TTL, và một bộ đếm không TTL là một breaker mở vĩnh viễn.
        Cache::add('voice:norm:ai_failures', 0, $window);
        Cache::increment('voice:norm:ai_failures');
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
