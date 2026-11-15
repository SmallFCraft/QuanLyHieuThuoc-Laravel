<?php

namespace App\Support;

/**
 * Key phiên âm cho tìm kiếm giọng nói tiếng Việt.
 *
 * Gộp vùng miền có chủ ý: người nói miền Nam nói "thuấc hạ sốc" và catalog có
 * "thuốc hạ sốt" phải tạo cùng một key, nếu không tìm kiếm giọng nói chỉ hoạt
 * động với accent mà nhận diện của browser dùng đến đã được hiệu chỉnh.
 */
final class PhienAmTiengViet
{
    /** Key phẳng dài hơn số này không bao giờ tham gia vào việc khớp gần đúng. */
    private const MAX_BLOCK_LENGTH = 24;

    private const BLOCK_LENGTH = 2;

    /**
     * Một âm tiết ngắn như vậy mang toàn bộ nghĩa phân biệt của một cụm từ.
     * Đã đo lường: bất kỳ độ dung chấp nào cho phép một trong chúng lệch sẽ gộp
     * "vitamin C" với "vitamin D".
     */
    private const ATOMIC_SYLLABLE = 2;

    /**
     * Vần đầu, phương án dài nhất trước. Mỗi quy tắc neo tại đầu từ và thay thế
     * một lần, nên marker hoa đã viết trước sẽ chặn các pattern phía sau.
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
     * Vần (âm gộp). Phải chạy trước các âm cuối — xem amTiet().
     *
     * Thứ tự trong bảng cũng quan trọng, cùng lý do đo: `uo` sẽ ăn phần "uo"
     * của "uối" và để lại một `i` không quy tắc nào nhận, nên "muối" tạo key `mUi`
     * trong khi "mới" tạo `mO` và cặp này không còn khớp. Vần dài nhất trước.
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
     * Nửa ký tự đơn của ONSETS, dạng bảng dịch thực đơn giản — dùng bởi dangTho()
     * để áp dụng lại các lớp ký tự ở những vị trí không phải đầu từ.
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

    public static function khoa(string $text): string
    {
        $words = preg_split('/\s+/', VietnameseText::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map([self::class, 'amTiet'], $words));
    }

    public static function amTiet(string $foldedWord): string
    {
        $syllable = $foldedWord;

        foreach (self::ONSETS as $pattern => $marker) {
            $syllable = preg_replace($pattern, $marker, $syllable, 1) ?? $syllable;
        }

        // x ở giữa từ đọc như s: "canxi" và "can si" là một từ được đọc hai cách.
        $syllable = str_replace('x', 'S', $syllable);

        foreach (self::RIMES as $pattern => $marker) {
            $syllable = preg_replace($pattern, $marker, $syllable) ?? $syllable;
        }

        // Sau các vần, không bao giờ trước: `t$ => T` nếu không sẽ ăn phần âm cuối
        // của "uốt", để "uo" không thể khớp với vần của chính nó.
        foreach (self::CODAS as $pattern => $marker) {
            $syllable = preg_replace($pattern, $marker, $syllable) ?? $syllable;
        }

        $syllable = strtr($syllable, self::VOWELS);

        // Chỉ với chữ cái: một ký tự lặp lại là một âm ("oo" -> "o"), nhưng chữ số
        // lặp lại là liều lượng. Gộp chữ số đã làm "500" và "50" cùng một key, và cả
        // hai đủ ngắn để ganNhau() sau đó chấp nhận chúng là một sản phẩm.
        return preg_replace('/([^0-9])\1+/', '$1', $syllable) ?? $syllable;
    }

    public static function khoaKhoi(string $key): ?string
    {
        $flat = self::boKhoangTrang($key);

        if ($flat === '' || strlen($flat) > self::MAX_BLOCK_LENGTH) {
            return null;
        }

        // Case-fold có chủ ý, khác với phonetic_key: đây là bộ lọc SQL thô, ganNhau()
        // mới là bộ lọc chính xác. Đã đo lường: "tiu" tạo key `tiu` và
        // "tiêu" tạo key `tIu`, nên prefix phân biệt hoa thường đưa chúng vào hai block
        // 'ti' và 'tI' và orWhereIn không bao giờ nạp ứng cử viên mà ganNhau() chấp nhận.
        // Fold chỉ mở rộng tập ứng cử viên; ganNhau() vẫn từ chối những kết quả không đúng.
        return strtolower(substr($flat, 0, self::BLOCK_LENGTH));
    }

    /**
     * Hai key mô tả cùng một cụm từ được nói ra.
     *
     * So sánh theo từng âm tiết thay vì như một chuỗi: một budget khoảng cách duy nhất
     * trên cả key đã gộp "vitamin C" với "vitamin D" ở mọi ngưỡng được đo, và trong
     * một nhà thuốc thì đó là cách sai không được phép.
     */
    public static function ganNhau(string $a, string $b): bool
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

                // Chữ số là số lượng, không phải phát âm — không accent nào đọc
                // 500 như 50. Đã đo lường: "500mg" so với "50mg" là lev 1 trên một
                // budget max(1, 5/4) = 1, nên hai liều lượng đã bị gộp. Đơn vị
                // rò rỉ theo cùng cách: "500mg" so với "500ml" cũng là lev 1.
                if (self::chuSo($syllable) !== '' || self::chuSo($other) !== '') {
                    return false;
                }

                $distance += levenshtein($syllable, $other);
            }

            return $distance <= max(1, intdiv(strlen(self::boKhoangTrang($a)), 4));
        }

        // Số âm tiết khác nhau: người nói đã tách một thành vài từ
        // ("pa ra xe ta mon"), nên chỉ key phẳng mới so sánh được.
        $flatA = self::boKhoangTrang($a);

        // Tách một âm tiết vẫn không được xuyên qua liều lượng: "500mg" đọc thành
        // "50 mg" là lev 1 trên key phẳng. Chỉ áp dụng khi cả hai bên đều có
        // chữ số — "omega 3" so với "ô mê ga ba" đọc chữ số ra thành từ,
        // và cặp đó phải tiếp tục khớp.
        $digitsA = self::chuSo($a);
        $digitsB = self::chuSo($b);

        if ($digitsA !== '' && $digitsB !== '' && $digitsA !== $digitsB) {
            return false;
        }

        if (levenshtein($flatA, self::boKhoangTrang($b)) <= max(1, intdiv(strlen($flatA), 3))) {
            return true;
        }

        // Lượt hai trên dạng thô hơn. amTiet() chỉ áp dụng các lớp ký tự §4.1
        // tại đầu từ, nên tên đọc từng âm tiết tích luỹ chung từng lần cho mỗi âm tiết
        // trong khi tên catalog đã nối lại chỉ tích luỹ một lần trong toàn bộ:
        // "pa na đôn" tạo key `pa La DoN` so với `panadol`, lev 3 trên budget
        // 2. Áp dụng lại cùng các lớp tại mỗi vị trí cho hai bên thẳng hàng và
        // cũng phục hồi "sa lón pát", "ni giô ran" và "côn la gien".
        //
        // Mở rộng budget khoảng cách không làm được việc này: cặp phân biệt gần nhất
        // mà phải đứng cách nhau ("vitamin e" / "betadine") nằm ở lev 4 trên 8,
        // cùng tỷ lệ với các cách đọc thương hiệu phải khớp, nên không ngưỡng nào
        // phân biệt được chúng. Đã đo lường trên dạng thô thay vì: không có cặp mới
        // nào bị gộp trên 26 tên sản phẩm phân biệt, và chỉ một cặp mới trên toàn bộ
        // index đã dựng — "Naturals" với "Nature's", một dòng sản phẩm.
        $coarseA = self::dangTho($flatA);

        return levenshtein($coarseA, self::dangTho(self::boKhoangTrang($b)))
            <= max(1, intdiv(strlen($coarseA), 3));
    }

    public static function khoangCach(string $a, string $b): int
    {
        return levenshtein(self::boKhoangTrang($a), self::boKhoangTrang($b));
    }

    private static function chuSo(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    /**
     * Các lớp ký tự §4.1 được áp dụng lại tại mỗi vị trí, không chỉ tại đầu từ.
     * Chỉ các ký tự thường còn sót lại được biến đổi: marker hoa là lớp mà amTiet()
     * đã quyết định, và viết lại chúng sẽ gộp `C` vào `K` và gộp các lớp mà bảng
     * có chủ ý giữ riêng.
     *
     * Chỉ dùng khi so sánh — không bao giờ lưu trữ. `phonetic_key` và `block_key`
     * giữ hình dạng riêng của chúng, nên việc này không cần rebuild index.
     */
    private static function dangTho(string $flatKey): string
    {
        $classed = preg_replace(
            ['/tr|ch/', '/gi/', '/ph/', '/qu/'],
            ['C', 'D', 'F', 'K'],
            $flatKey
        ) ?? $flatKey;

        $classed = strtr($classed, self::MID_WORD_ONSETS);

        return preg_replace('/([^0-9])\1+/', '$1', $classed) ?? $classed;
    }

    private static function boKhoangTrang(string $key): string
    {
        return str_replace(' ', '', $key);
    }
}
