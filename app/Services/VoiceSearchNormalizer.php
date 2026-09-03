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
     * Ghi nhớ "từ này có trong tên sản phẩm không" trong một lượt normalize().
     *
     * Service được resolve mỗi request nên bộ nhớ này chết theo request — không phải
     * cache dài hạn, chỉ để một lượt không hỏi DB cùng một từ bốn lần.
     *
     * @var array<string, bool>
     */
    private array $wordInName = [];

    /**
     * @param array<int, string> $alternatives Web Speech trả tối đa 3, tốt nhất trước.
     * @return array{text: string, heard: string, source: string, confident: bool}
     */
    public function normalize(array $alternatives): array
    {
        // heard giữ nguyên dấu câu và cụm mệnh lệnh — nút "Dùng nguyên văn" cần đúng
        // chữ khách nói. Nhưng ký tự điều khiển thì không phải chữ ai nói. Thay bằng
        // khoảng trắng như trimPunctuation(), vì cùng một lý do: xoá hẳn thì
        // "thuốc\x07hạ sốt" dính thành "thuốchạ sốt".
        $heard = trim(preg_replace('/\p{C}+/u', ' ', (string) ($alternatives[0] ?? '')) ?? '');
        $alternatives = $this->clean($alternatives);

        if ($alternatives === []) {
            return $this->result('', $heard, self::SOURCE_RAW, false);
        }

        foreach ($alternatives as $alternative) {
            if ($this->isRealQuery($alternative)) {
                // confident không còn nghĩa "một tầng đã trả ra chữ" mà là "chữ đó
                // đứng được trên catalog". Client dùng cờ này để nói "Nghe được" hay
                // "Đoán là", nên một cờ dễ dãi là một lời nói dối trên giao diện.
                return $this->result(
                    $alternative,
                    $heard,
                    self::SOURCE_EXACT,
                    $this->wordsCoOccur($alternative)
                );
            }
        }

        $phonetic = $this->matchPhonetically($alternatives);

        if ($phonetic !== null) {
            return $this->result($phonetic, $heard, self::SOURCE_PHONETIC, $this->wordsCoOccur($phonetic));
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
        // \p{C} trước \p{P}: ký tự điều khiển không phải dấu câu, nên bộ cũ để
        // "\x07abc" đi nguyên vào ô tìm kiếm và vào ?q=. Bỏ ở mọi vị trí, không chỉ
        // hai đầu — không có ký tự điều khiển nào mang nghĩa trong một tên thuốc.
        //
        // Thay bằng KHOẢNG TRẮNG, không phải chuỗi rỗng: đo 2026-09-02, xoá hẳn thì
        // "thuốc\x07hạ sốt" dính thành "thuốchạ sốt" — một từ không có trong catalog,
        // tức là đổi một lỗi hiển thị thành một lỗi khớp. Ký tự điều khiển ngăn cách
        // chứ không nối. Hai đầu do regex dưới và trim() dọn.
        $value = preg_replace('/\p{C}+/u', ' ', $value) ?? $value;

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
     * Cụm này đã đúng sẵn, không có gì để sửa.
     *
     * Đây là câu hỏi của T0 — "chuỗi này có cần sửa không" — chứ không phải "chuỗi này
     * có liên quan catalog không". Hai câu hỏi khác nhau, và trả lời sai câu thứ nhất
     * bằng câu thứ hai là lỗi gốc của cả tầng.
     *
     * KHÔNG dùng Product::storefrontSearch() làm cổng, dù nó là hàm tìm kiếm thật của
     * shop. Đo 2026-09-02 trên catalog 3.146 sản phẩm: nó khớp CHUỖI CON (`la` nằm
     * trong "Salonpas", "Calcium") và chỉ cần từng từ khớp đâu đó, kể cả trong
     * `ingredients_folded`. Nên "ê a ê a" khớp 3.146 sản phẩm (cả catalog), "lá la la
     * la" 1.271, "ba la bù la" 125 — và T0 tuyên bố `exact` + `confident: true` cho
     * tiếng ồn rồi dừng, khiến tầng phiên âm và tầng AI không bao giờ chạy.
     *
     * Hai điều kiện ở đây, mỗi điều kiện có số đo riêng. Tập kiểm: 12 câu vô nghĩa
     * (phải chặn), 9 câu nói đúng (phải nhận), 57 đáp án thật của corpus (phải nhận),
     * 66 câu nghe lệch (phải chặn để T1 chạy).
     *
     *   lỏng (storefrontSearch)     0/12   9/9   57/57   —
     *   AND mọi từ, trọn từ         9/12   9/9   54/57   <- mất đáp án thật
     *   mọi từ DÀI phải khớp       10/12   9/9   57/57   15/66
     *   + quá nửa câu là từ dài    12/12   9/9   57/57   19/66  <- dùng cái này
     *
     * Phương án AND bị loại vì catalog viết khác cách khách nói: 0 sản phẩm nào có cả
     * "khau trang" và "y te" trong tên, trong khi "khẩu trang y tế" là cách khách hỏi
     * và có 8 sản phẩm đúng ý.
     *
     * Điều kiện quá-nửa chặn một câu toàn âm tiết vụn qua cổng nhờ vài từ khớp ngẫu
     * nhiên: "pa ra xe ta mon năm trăm" chỉ có 3/7 từ được xét, cả ba khớp, nên tên
     * thuốc đọc rời không bao giờ được ghép lại.
     *
     * Chặt ở đây an toàn vì T0 không còn là cửa cuối: trượt T0 thì xuống T1 (có cổng
     * riêng, xem matchPhonetically), rồi AI, rồi nguyên văn. Chuỗi khách nói không bao
     * giờ mất — chỉ khác ở chỗ hệ thống có tuyên bố chắc chắn hay không.
     */
    private function isRealQuery(string $term): bool
    {
        $all = array_unique(Product::searchWords($term));

        $long = array_filter($all, fn (string $word) => strlen($word) >= self::MEANINGFUL_WORD_LENGTH);

        if ($long === []) {
            return false;
        }

        foreach ($long as $word) {
            if (! $this->nameHasWord($word)) {
                return false;
            }
        }

        return count($long) * 2 >= count($all);
    }

    /**
     * Từ nào ngắn hơn thế thì không đủ để một mình chứng minh cụm là thật.
     *
     * Đo được: câu vô nghĩa toàn âm tiết ngắn ("la", "lo", "ba", "bu") và chúng chỉ
     * khớp vì `LIKE '%la%'` khớp CHUỖI CON của "Salonpas", "Calcium". Từ mang nghĩa
     * trong catalog thì dài hơn.
     */
    private const MEANINGFUL_WORD_LENGTH = 3;

    /**
     * Thêm điều kiện "từ này là một TỪ TRỌN trong name_folded" vào một query.
     *
     * Bốn mẫu LIKE thay cho một `%tu%`, vì `%la%` khớp cả "Salonpas". `name_folded`
     * đã fold nên các từ cách nhau đúng một khoảng trắng.
     *
     * Bốn LIKE chứ không phải RLIKE hay concat(): bộ test chạy trên sqlite :memory:
     * (phpunit.xml) còn production là MySQL, và cách này là cách duy nhất đã kiểm
     * chạy đúng trên cả hai.
     *
     * Một chỗ duy nhất giữ luật khớp-từ-trọn: nameHasWord() hỏi MỘT từ, wordsCoOccur()
     * chồng N điều kiện lên cùng một query để hỏi "cùng một sản phẩm". Hai bản sao của
     * bốn dòng LIKE này là hai chỗ phải sửa khi luật đổi.
     */
    private function whereNameHasWord($query, string $word): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word);

        $query->where(function ($inner) use ($escaped): void {
            $inner->where('name_folded', $escaped)
                ->orWhere('name_folded', 'like', $escaped . ' %')
                ->orWhere('name_folded', 'like', '% ' . $escaped)
                ->orWhere('name_folded', 'like', '% ' . $escaped . ' %');
        });
    }

    /**
     * Từ này xuất hiện như một TỪ TRỌN trong tên sản phẩm nào.
     *
     * Ghi nhớ kết quả theo từ trong suốt một lượt normalize(): isRealQuery() và
     * countNameWords() hỏi về cùng những từ đó nhiều lần — T0 hỏi chuỗi gốc, rồi T1 hỏi
     * lại chuỗi gốc và chuỗi đã sửa. Đo được 2026-09-02: "thuấc hạ sốc" tốn 6 truy vấn
     * mà 4 trong số đó lặp lại cùng một từ.
     */
    private function nameHasWord(string $word): bool
    {
        if (array_key_exists($word, $this->wordInName)) {
            return $this->wordInName[$word];
        }

        $query = Product::notExpired();
        $this->whereNameHasWord($query, $word);

        return $this->wordInName[$word] = $query->exists();
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
        $fixed = $this->substitute($best['window'], $best['term']);

        // Bản đã sửa phải HƠN bản gốc, không chỉ khác nó. Đo được 2026-09-02: siết
        // cổng T0 mà không có bước này làm tổng corpus tụt 52/94 xuống 38/94 — chặn
        // ở T0 chỉ đẩy chuỗi xuống T1, và T1 vốn tuyên bố confident bất kể kết quả.
        // Hệ quả thật: "ba la bù la" thành "bã la bù la", "ước gì" thành "ướt gì".
        return $this->countNameWords($fixed) > $this->countNameWords($best['window']['source'])
            ? $fixed
            : null;
    }

    /**
     * Bao nhiêu từ đủ dài của cụm khớp trọn từ trong tên sản phẩm.
     *
     * Thước để so bản gốc với bản đã sửa: sửa đúng thì số từ khớp tăng, sửa bừa thì
     * không. Dùng chung nameHasWord() và ngưỡng độ dài với isRealQuery() nên hai tầng
     * đo cùng một thứ và không mâu thuẫn nhau.
     */
    private function countNameWords(string $term): int
    {
        $hits = 0;

        foreach (array_unique(Product::searchWords($term)) as $word) {
            if (strlen($word) < self::MEANINGFUL_WORD_LENGTH) {
                continue;
            }

            if ($this->nameHasWord($word)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * Mọi từ đủ dài của cụm cùng nằm trong tên của MỘT sản phẩm.
     *
     * Đây là điều kiện mà commit 5bd1889 hứa ("require a real product-name match")
     * nhưng chưa ship: cổng đã ship là so sánh tương đối countNameWords($fixed) >
     * countNameWords($source), nên "Par xê ta môn năm trăm" đi qua vì 4 > 3.
     *
     * MỘT truy vấn cho cả cụm, không phải N như nameHasWord(): các điều kiện AND
     * chồng nhau trong cùng một câu lệnh. Không thay isRealQuery() — nó cố tình lỏng
     * để "khẩu trang y tế" (0 sản phẩm có cả 4 từ trong TÊN) vẫn tìm được qua
     * ingredients_folded. Cổng này chỉ quyết định CỜ, không quyết định chuỗi trả về.
     *
     * Đo trên catalog thật 2026-09-02: lies 33 -> 14, confident-mà-catalog-rỗng
     * 15 -> 1, coverage giữ nguyên 55/94, 4 ca đúng bị hạ cờ oan.
     */
    private function wordsCoOccur(string $term): bool
    {
        $words = array_unique(array_filter(
            Product::searchWords($term),
            fn (string $word) => strlen($word) >= self::MEANINGFUL_WORD_LENGTH
        ));

        if ($words === []) {
            return false;
        }

        $query = Product::notExpired();

        foreach ($words as $word) {
            $this->whereNameHasWord($query, $word);
        }

        return $query->exists();
    }

    /**
     * Cửa sổ 1-N từ liền nhau, dài trước ngắn sau, gộp trùng qua mọi alternative.
     *
     * Bề rộng và số cửa sổ đọc từ config vì phải nới cùng nhau: vòng lặp đi dài-trước
     * nên cap hẹp tiêu hết ngân sách ngay ở tầng dài nhất, xem config/search.php.
     *
     * @return array<string, array{key: string, text: string, source: string}>
     */
    private function windows(array $alternatives): array
    {
        $cap = max(1, (int) config('search.voice.max_windows', 14));
        $maxWords = max(1, (int) config('search.voice.max_window_words', 5));
        $windows = [];

        foreach ($alternatives as $alternative) {
            $words = preg_split('/\s+/', $alternative, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $taken = 0;

            for ($size = $maxWords; $size >= 1 && $taken < $cap; $size--) {
                for ($i = 0; $i + $size <= count($words) && $taken < $cap; $i++) {
                    $text = implode(' ', array_slice($words, $i, $size));
                    $key = VoicePhonetics::key($text);

                    // $taken++ SAU khi loại: khoá rỗng và khoá trùng không sinh ứng
                    // viên nào, nên tính chúng vào ngân sách là thu hẹp vùng phủ mà
                    // không được gì. Ba alternative của Web Speech gần giống nhau nên
                    // phần lớn cửa sổ là bản sao.
                    if ($key === '' || isset($windows[$key])) {
                        continue;
                    }

                    $taken++;

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

        // isRealQuery(), không phải catalogHas(): AI được đưa danh sách ứng viên nên
        // câu trả lời của nó phải khớp catalog trọn vẹn, không phải khớp một từ. Với
        // tiêu chí lỏng, đo được provider trả "ba la bù la" và lớp kiểm này NHẬN, vì
        // chuỗi đó "tìm ra" 125 sản phẩm qua LIKE chuỗi con.
        if ($this->isRealQuery($answer)) {
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
