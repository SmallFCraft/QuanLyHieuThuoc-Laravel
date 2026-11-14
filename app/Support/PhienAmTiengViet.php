<?php

namespace App\Support;

/**
 * Key phien am cho tim kiem giong noi tieng Viet.
 *
 * Gop vung mien co chu y: nguoi noi mien Nam goi "thuac ha soc" va catalog co
 * "thuoc ha sot" phai tao cung mot key, neu khong tim kiem giong noi chi hoat
 * dong voi accent ma nhan dien cua browser dung den da duoc hieu chinh.
 */
final class PhienAmTiengViet
{
    /** Key phang dai hon so nay khong bao gio tham gia vao viec khop gan dung. */
    private const MAX_BLOCK_LENGTH = 24;

    private const BLOCK_LENGTH = 2;

    /**
     * Mot am tiet ngan nhu vay mang toan bo nghia phan biet cua mot cum tu.
     * Da do luong: bat ky do dung chap nao cho phep mot trong chung lech se gop
     * "vitamin C" voi "vitamin D".
     */
    private const ATOMIC_SYLLABLE = 2;

    /**
     * Van dau, phuong an dai nhat truoc. Moi quy tac neo tai dau tu va thay the
     * mot lan, nen marker hoa da viet truoc se chan cac pattern phia sau.
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
     * Van (am gop). Phai chay truoc cac am cuoi — xem syllable().
     *
     * Thu tu trong bang cung quan trong, cung ly do do: `uo` se an phan "uo"
     * cua "uoi" va de lai mot `i` khong quy tac nao nhan, nen "muoi" tao key `mUi`
     * trong khi "moi" tao `mO` va cap nay khong con khop. Van dai nhat truoc.
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
     * Nua ky tu don cua ONSETS, dang bang dich thuc don gian — dung boi coarse()
     * de ap dung lai cac lop ky tu o nhung vi tri khong phai dau tu.
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

        // x o giua tu doc nhu s: "canxi" va "can si" la mot tu duoc doc hai cach.
        $syllable = str_replace('x', 'S', $syllable);

        foreach (self::RIMES as $pattern => $marker) {
            $syllable = preg_replace($pattern, $marker, $syllable) ?? $syllable;
        }

        // Sau cac van, khong bao gio truoc: `t$ => T` neu khong se an phan am cuoi
        // cua "uot", de "uo" khong the khop voi van cua chinh no.
        foreach (self::CODAS as $pattern => $marker) {
            $syllable = preg_replace($pattern, $marker, $syllable) ?? $syllable;
        }

        $syllable = strtr($syllable, self::VOWELS);

        // Chi voi chu cai: mot ky tu lap lai la mot am ("oo" -> "o"), nhung chu so
        // lap lai la lieu luong. Gop chu so da lam "500" va "50" cung mot key, va ca
        // hai du ngan de within() sau do chap nhan chung la mot san pham.
        return preg_replace('/([^0-9])\1+/', '$1', $syllable) ?? $syllable;
    }

    public static function khoaKhoi(string $key): ?string
    {
        $flat = self::boKhoangTrang($key);

        if ($flat === '' || strlen($flat) > self::MAX_BLOCK_LENGTH) {
            return null;
        }

        // Case-fold co chu y, khac voi phonetic_key: day la bo loc SQL tho, within()
        // moi la bo loc chinh xac. Da do luong: "tiu" tao key `tiu` va
        // "tieu" tao key `tIu`, nen prefix phan biet hoa thuong dua chung vao hai block
        // 'ti' va 'tI' va orWhereIn khong bao gio nap ung cu vien ma within() chap nhan.
        // Fold chi mo rong tap ung cu vien; within() van tu choi nhung ket qua khong dung.
        return strtolower(substr($flat, 0, self::BLOCK_LENGTH));
    }

    /**
     * Hai key mo ta cung mot cum tu duoc noi ra.
     *
     * So sanh theo tung am tiet thay vi nhu mot chuoi: mot budget khoang cach duy nhat
     * tren ca key da gop "vitamin C" voi "vitamin D" o moi nguong duoc do, va trong
     * mot nha thuoc thi do la cach sai khong duoc phep.
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

                // Chu so la so luong, khong phai phat am — khong accent nao doc
                // 500 nhu 50. Da do luong: "500mg" so voi "50mg" la lev 1 tren mot
                // budget max(1, 5/4) = 1, nen hai lieu luong da bi gop. Don vi
                // roi vi cung cach: "500mg" so voi "500ml" cung la lev 1.
                if (self::chuSo($syllable) !== '' || self::chuSo($other) !== '') {
                    return false;
                }

                $distance += levenshtein($syllable, $other);
            }

            return $distance <= max(1, intdiv(strlen(self::boKhoangTrang($a)), 4));
        }

        // So am tiet khac nhau: nguoi noi da tach mot thanh vai tu
        // ("pa ra xe ta mon"), nen chi key phang moi so sanh duoc.
        $flatA = self::boKhoangTrang($a);

        // Tach mot am tiet van khong duoc xuyen qua lieu luong: "500mg" doc thanh
        // "50 mg" la lev 1 tren key phang. Chi ap dung khi ca hai ben deu co
        // chu so — "omega 3" so voi "o me ga ba" doc chu so ra thanh tu,
        // va cap do phai tiep tuc khop.
        $digitsA = self::chuSo($a);
        $digitsB = self::chuSo($b);

        if ($digitsA !== '' && $digitsB !== '' && $digitsA !== $digitsB) {
            return false;
        }

        if (levenshtein($flatA, self::boKhoangTrang($b)) <= max(1, intdiv(strlen($flatA), 3))) {
            return true;
        }

        // Luot hai tren dang tho hon. syllable() chi ap dung cac lop ky tu §4.1
        // tai dau tu, nen ten doc tung am tiet tich luy chung tung lan cho moi am tiet
        // trong khi ten catalog da noi lai chi tich luy mot lan trong toan bo:
        // "pa na don" tao key `pa La DoN` so voi `panadol`, lev 3 tren budget
        // 2. Ap dung lai cung cac lop tai moi vi tri cho hai ben thang hang va
        // cung phuc hoi "sa lon pat", "ni gio ran" va "con la gien".
        //
        // Mo rong budget khoang cach khong lam duoc viec nay: cap phan biet gan nhat
        // ma phai dung cach nhau ("vitamin e" / "betadine") nam o lev 4 tren 8,
        // cung ty le voi cac cach doc thuong hieu phai khop, nen khong nguong nao
        // phan biet duoc chung. Da do luong tren dang tho thay vi: khong co cap moi
        // nao bi gop tren 26 ten san pham phan biet, va chi mot cap moi tren toan bo
        // index da dung — "Naturals" voi "Nature's", mot dong san pham.
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
     * Cac lop ky tu §4.1 duoc ap dung lai tai moi vi tri, khong chi tai dau tu.
     * Chi cac ky tu thuong con sot lai duoc bien doi: marker hoa la lop ma syllable()
     * da quyet dinh, va viet lai chung se gop `C` vao `K` va gop cac lop ma bang
     * co chu y giu rieng.
     *
     * Chi dung khi so sanh — khong bao gio luu tru. `phonetic_key` va `block_key`
     * giu hinh dang rieng cua chung, nen viec nay khong can rebuild index.
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
