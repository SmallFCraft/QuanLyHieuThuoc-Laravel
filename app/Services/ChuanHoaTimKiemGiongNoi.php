<?php

namespace App\Services;

use App\Models\SanPham;
use App\Models\PhienAmGiongNoi;
use App\Support\VietnameseText;
use App\Support\PhienAmTiengViet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chuan hoa chuoi Web Speech nghe duoc thanh mot tu khoa tim kiem dung duoc.
 *
 * Bon tang, re truoc dat sau. Mot cau khop duoc o tang re khong bao gio di toi
 * tang dat — do la dieu giu cho tinh nang chiu duoc ngan nguoi dung cung luc, va
 * la bat bien ma ChuanHoaTimKiemGiongNoiTest khoa lai.
 *
 * Nhan dien chay trong browser: service nay chi thay text. Do 2026-09-01, ca hai
 * AI provider cua repo deu khong co STT tieng Viet dung duoc — mot model tra "pm",
 * mot model bia ten thuoc khong ton tai.
 */
class ChuanHoaTimKiemGiongNoi
{
    public const SOURCE_EXACT = 'exact';
    public const SOURCE_PHONETIC = 'phonetic';
    public const SOURCE_AI = 'ai';
    public const SOURCE_RAW = 'raw';

    /** Cum mo dau dai nhat trong LEAD_PHRASES ("toi muon tim" — ba tu). */
    private const MAX_LEAD_WORDS = 3;

    /**
     * Cum mo dau mang y dinh, khong mang tu khoa. Viet co dau cho nguoi doc;
     * cumMoDau() fold lai truoc khi so.
     *
     * Co y KHONG co "co", "con", "ban co". Chung la mot am tiet ngan va fold() gop
     * chung voi nhung tu mang nghia: fold("con") = fold("con") = "con", fold("co") =
     * fold("co") = fold("co") = "co". Do duoc khi thu: "con toi bi sot" mat chu ngu
     * thanh "toi bi sot", va te hon, "con la gien" (collagen doc roi am) thanh
     * "La gien" — cat dut chinh ten thuoc. Mot cum menh lenh chi dang bo khi no
     * khong the la mot phan cua ten hang.
     */
    private const LEAD_PHRASES = [
        'tìm', 'tìm kiếm', 'tìm giúp tôi', 'tìm cho tôi', 'tìm cho mình',
        'cho tôi', 'cho tôi xem', 'cho mình', 'cho mình xem', 'cho em', 'cho em hỏi',
        'tôi muốn', 'tôi muốn tìm', 'tôi muốn mua', 'tôi muốn xem',
        'tôi cần', 'tôi cần tìm', 'tôi cần mua',
        'mua', 'kiếm', 'tra cứu',
    ];

    /** @var array<string, true>|null */
    private static ?array $cumMoDau = null;

    /** Diem cong theo nguon cua cum, de ten san pham thang mot token trung. */
    private const KIND_BONUS = ['product' => 20, 'category' => 10, 'token' => 0];

    /** So cum ung vien dua vao prompt. Nhieu hon la tra tien token cho nhieu. */
    private const AI_CANDIDATES = 40;

    /** Gia tri cache cho mot cau ma AI cung khong giup duoc. */
    private const AI_NO_HELP = "\0no-help";

    /**
     * Ghi nho "tu nay co trong ten san pham khong" trong mot luot chuanHoa().
     *
     * Service duoc resolve moi request nen bo nho nay chet theo request — khong phai
     * cache dai han, chi de mot luot khong hoi DB cung mot tu bon lan.
     *
     * @var array<string, bool>
     */
    private array $tuTrongTen = [];

    /** Ung vien T1 da loai, giu lai cho prompt AI trong cung mot luot chuanHoa(). */
    private ?array $phanDuPhienAm = null;

    /**
     * @param array<int, string> $alternatives Web Speech tra toi da 3, tot nhat truoc.
     * @return array{text: string, heard: string, source: string, confident: bool}
     */
    public function chuanHoa(array $alternatives): array
    {
        // heard giu nguyen dau cau va cum menh lenh — nut "Dung nguyen van" can dung
        // chu khach noi. Nhung ky tu dieu khien thi khong phai chu ai noi. Thay bang
        // khoang trang nhu catDauCau(), vi cung mot ly do: xoa han thi
        // "thuoc\x07ha sot" dinh thanh "thuocha sot".
        $heard = trim(preg_replace('/\p{C}+/u', ' ', (string) ($alternatives[0] ?? '')) ?? '');
        $alternatives = $this->donDep($alternatives);

        if ($alternatives === []) {
            return $this->ketQua('', $heard, self::SOURCE_RAW, false);
        }

        foreach ($alternatives as $alternative) {
            if ($this->laTruyVanThat($alternative)) {
                // confident khong con nghia "mot tang da tra ra chu" ma la "chu do
                // dung duoc tren catalog". Client dung co nay de noi "Nghe duoc" hay
                // "Doan la", nen mot co de dai la mot loi noi doi tren giao dien.
                return $this->ketQua(
                    $alternative,
                    $heard,
                    self::SOURCE_EXACT,
                    $this->tuCungXuatHien($alternative)
                );
            }
        }

        $phienAm = $this->khopPhienAm($alternatives);

        if ($phienAm !== null) {
            return $this->ketQua($phienAm, $heard, self::SOURCE_PHONETIC, $this->tuCungXuatHien($phienAm));
        }

        $ai = $this->hoiAi($alternatives);

        if ($ai !== null) {
            return $this->ketQua($ai, $heard, self::SOURCE_AI, false);
        }

        return $this->ketQua($alternatives[0], $heard, self::SOURCE_RAW, false);
    }

    /**
     * @param array<int, string> $alternatives
     * @return array<int, string>
     */
    private function donDep(array $alternatives): array
    {
        return array_values(array_filter(array_map(
            fn ($value) => $this->boCumMoDau($this->catDauCau((string) $value)),
            $alternatives
        ), fn (string $value) => $value !== ''));
    }

    /**
     * Dau cau o hai dau bi cat; dau cau giua cau thi khong — "omega-3" va
     * "vitamin B6" la ten hang, khong phai cau co dau.
     *
     * Chrome vi-VN LUON cham het cau, nen khong cat la de dau cham di vao o tim kiem,
     * vao `?q=` va vao nhan "Xem tat ca ket qua cho …". fold() bo dau cau luc so khop
     * nhung chuoi tra ve cho khach thi khong di qua fold().
     */
    private function catDauCau(string $value): string
    {
        // \p{C} truoc \p{P}: ky tu dieu khien khong phai dau cau, nen bo cu de
        // "\x07abc" di nguyen vao o tim kiem va vao ?q=. Bo o moi vi tri, khong chi
        // hai dau — khong co ky tu dieu khien nao mang nghia trong mot ten thuoc.
        //
        // Thay bang KHOANG TRANG, khong phai chuoi rong: do 2026-09-02, xoa han thi
        // "thuoc\x07ha sot" dinh thanh "thuocha sot" — mot tu khong co trong catalog,
        // tuc la doi mot loi hien thi thanh mot loi khop. Ky tu dieu khien ngan cach
        // chu khong noi. Hai dau do regex duoi va trim() don.
        $value = preg_replace('/\p{C}+/u', ' ', $value) ?? $value;

        return preg_replace('/^[\p{P}\s]+|[\p{P}\s]+$/u', '', $value) ?? trim($value);
    }

    /**
     * Bo cum mo dau mang y dinh chu khong mang tu khoa.
     *
     * Do tren catalog: tu `tim` khop 81 san pham (tim la co quan), `cho` khop 639,
     * `toi` khop 87. Nen "Tim giup toi men vi sinh" duoc T0 tuyen bo exact nho chinh
     * tu menh lenh roi dung — tang phien am khong bao gio chay de sua phan khach hoi.
     *
     * So tren chuoi da fold vi khach noi co dau, va dai truoc ngan sau vi neu khong
     * thi `tim` an truoc `tim kiem`. Chi bo khi phan con lai van la mot truy van:
     * khach noi dung mot tu "Tim" thi do la tat ca nhung gi ta co.
     */
    private function boCumMoDau(string $value): string
    {
        $words = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $phrases = self::cumMoDau();

        // Fold tung tu rieng, khong fold ca cau: fold() tach "omega-3" thanh hai tu,
        // va mot chi so lech thi cat mat phan khach that su hoi.
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
     * Khoa la dang da fold, nen bang ben duoi viet co dau cho nguoi doc: them mot cum
     * moi khong can biet fold() tra ra gi.
     *
     * @return array<string, true>
     */
    private static function cumMoDau(): array
    {
        return self::$cumMoDau ??= array_fill_keys(array_map(
            fn (string $phrase) => VietnameseText::fold($phrase),
            self::LEAD_PHRASES
        ), true);
    }

    /**
     * Cum nay da dung san, khong co gi de sua.
     *
     * Day la cau hoi cua T0 — "chuoi nay co can sua khong" — chu khong phai "chuoi nay
     * co lien quan catalog khong". Hai cau hoi khac nhau, va tra loi sai cau thu nhat
     * bang cau thu hai la loi goc cua ca tang.
     *
     * KHONG dung SanPham::storefrontSearch() lam cong, du no la ham tim kiem that cua
     * shop. Do 2026-09-02 tren catalog 3.146 san pham: no khop CHUOI CON (`la` nam
     * trong "Salonpas", "Calcium") va chi can tung tu khop dau do, ke ca trong
     * `ingredients_folded`. Nen "e a e a" khop 3.146 san pham (ca catalog), "la la la
     * la" 1.271, "ba la bu la" 125 — va T0 tuyen bo `exact` + `confident: true` cho
     * tieng on roi dung, khien tang phien am va tang AI khong bao gio chay.
     *
     * Hai dieu kien o day, moi dieu kien co so do rieng. Tap kiem: 12 cau vo nghia
     * (phai chan), 9 cau noi dung (phai nhan), 57 dap an that cua corpus (phai nhan),
     * 66 cau nghe lech (phai chan de T1 chay).
     *
     *   long (storefrontSearch)     0/12   9/9   57/57   —
     *   AND moi tu, tron tu         9/12   9/9   54/57   <- mat dap an that
     *   moi tu DAI phai khop       10/12   9/9   57/57   15/66
     *   + qua nua cau la tu dai    12/12   9/9   57/57   19/66  <- dung cai nay
     *
     * Phuong an AND bi loai vi catalog viet khac cach khach noi: 0 san pham nao co ca
     * "khau trang" va "y te" trong ten, trong khi "khau trang y te" la cach khach hoi
     * va co 8 san pham dung y.
     *
     * Dieu kien qua-nua chan mot cau toan am tiet vun qua cong nho vai tu khop ngau
     * nhien: "pa ra xe ta mon nam tram" chi co 3/7 tu duoc xet, ca ba khop, nen ten
     * thuoc doc roi khong bao gio duoc ghep lai.
     *
     * Chat o day an toan vi T0 khong con la cua cuoi: truot T0 thi xuong T1 (co cong
     * rieng, xem khopPhienAm), roi AI, roi nguyen van. Chuoi khach noi khong bao
     * gio mat — chi khac o cho he thong co tuyen bo chac chan hay khong.
     */
    private function laTruyVanThat(string $term): bool
    {
        $all = array_unique(SanPham::searchWords($term));

        // Cong so luong TRUOC moi truy van: T1 co max_windows chan, T0 thi khong co gi.
        // tenCoTu() la mot truy van cho moi TU PHAN BIET, hai trong bon nhanh LIKE
        // cua no khong dung duoc index name_folded. Do 2026-09-02: 3 alternative x 50 tu
        // THAT trong catalog ton 100 truy van / 284ms — duong ngat som ben duoi chi cuu
        // duoc cau co tu truot, con cau toan tu that thi tra du gia.
        if (count($all) > (int) config('search.voice.t0_max_words', 12)) {
            return false;
        }

        $long = array_filter($all, fn (string $word) => strlen($word) >= self::MEANINGFUL_WORD_LENGTH);

        if ($long === []) {
            return false;
        }

        foreach ($long as $word) {
            if (! $this->tenCoTu($word)) {
                return false;
            }
        }

        return count($long) * 2 >= count($all);
    }

    /**
     * Tu nao ngan hon the thi khong du de mot minh chung minh cum la that.
     *
     * Do duoc: cau vo nghia toan am tiet ngan ("la", "lo", "ba", "bu") va chung chi
     * khop vi `LIKE '%la%'` khop CHUOI CON cua "Salonpas", "Calcium". Tu mang nghia
     * trong catalog thi dai hon.
     */
    private const MEANINGFUL_WORD_LENGTH = 3;

    /**
     * Them dieu kien "tu nay la mot TU TRON trong name_folded" vao mot query.
     *
     * Bon mau LIKE thay cho mot `%tu%`, vi `%la%` khop ca "Salonpas". `ten_khong_dau`
     * da fold nen cac tu cach nhau dung mot khoang trang.
     *
     * Bon LIKE chu khong phai RLIKE hay concat(): bo test chay tren sqlite :memory:
     * (phpunit.xml) con production la MySQL, va cach nay la cach duy nhat da kiem
     * chay dung tren ca hai.
     *
     * Mot cho duy nhat giu luat khop-tu-tron: dieuKienTuTrongTen() hoi MOT tu, tuCungXuatHien()
     * chong N dieu kien len cung mot query de hoi "cung mot san pham". Hai ban sao cua
     * bon dong LIKE nay la hai cho phai sua khi luat doi.
     */
    private function dieuKienTuTrongTen($query, string $word): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word);

        $query->where(function ($inner) use ($escaped): void {
            $inner->where('ten_khong_dau', $escaped)
                ->orWhere('ten_khong_dau', 'like', $escaped . ' %')
                ->orWhere('ten_khong_dau', 'like', '% ' . $escaped)
                ->orWhere('ten_khong_dau', 'like', '% ' . $escaped . ' %');
        });
    }

    /**
     * Tu nay xuat hien nhu mot TU TRON trong ten san pham nao.
     *
     * Ghi nho ket qua theo tu trong suot mot luot chuanHoa(): laTruyVanThat() va
     * demTuTrongTen() hoi ve cung nhung tu do nhieu lan — T0 hoi chuoi goc, roi T1 hoi
     * lai chuoi goc va chuoi da sua. Do duoc 2026-09-02: "thuac ha soc" ton 6 truy van
     * ma 4 trong so do lap lai cung mot tu.
     */
    private function tenCoTu(string $word): bool
    {
        if (array_key_exists($word, $this->tuTrongTen)) {
            return $this->tuTrongTen[$word];
        }

        $query = SanPham::notExpired();
        $this->dieuKienTuTrongTen($query, $word);

        return $this->tuTrongTen[$word] = $query->exists();
    }

    /**
     * Thay doan nghe lech bang cum trong catalog co khoa phien am tuong duong.
     */
    private function khopPhienAm(array $alternatives): ?string
    {
        $windows = $this->cuaSo($alternatives);

        if ($windows === []) {
            return null;
        }

        $candidates = $this->traCuu($windows);

        // Docblock cua ungVienChoPrompt() noi "mot truy van phuc vu ca hai tang";
        // truoc day no tu goi lai cuaSo() + traCuu(), nen that ra la hai. Ghi ngay
        // sau traCuu() chu khong o cho return: bon trong nam duong ra cua ham nay tra
        // null SAU traCuu(), va dung nhung duong do moi la luc hoiAi() duoc goi.
        $this->phanDuPhienAm = ['windows' => $windows, 'candidates' => $candidates];

        if ($candidates->isEmpty()) {
            return null;
        }

        $best = null;

        foreach ($windows as $window) {
            foreach ($candidates as $candidate) {
                if (! PhienAmTiengViet::ganNhau($window['key'], (string) $candidate->phonetic_key)) {
                    continue;
                }

                $score = $this->diem($window, $candidate);

                if ($best === null || $score > $best['score']) {
                    $best = ['score' => $score, 'window' => $window, 'term' => (string) $candidate->term];
                }
            }
        }

        if ($best === null) {
            return null;
        }

        // Chi doan nghe lech bi thay; phan con lai cua cau giu nguyen, vi khach co
        // the da noi them boi canh ("cho em be") ma catalog khong chua.
        $fixed = $this->thayThe($best['window'], $best['term']);

        // Ban da sua phai HON ban goc, khong chi khac no. Do duoc 2026-09-02: siet
        // cong T0 ma khong co buoc nay lam tong corpus tut 52/94 xuong 38/94 — chan
        // o T0 chi day chuoi xuong T1, va T1 von tuyen bo confident bat ke ket qua.
        // He qua that: "ba la bu la" thanh "ba la bu la", "uoc gi" thanh "uot gi".
        return $this->demTuTrongTen($fixed) > $this->demTuTrongTen($best['window']['source'])
            ? $fixed
            : null;
    }

    /**
     * Bao nhieu tu du dai cua cum khop tron tu trong ten san pham.
     *
     * Thuoc de so ban goc voi ban da sua: sua dung thi so tu khop tang, sua bua thi
     * khong. Dung chung tenCoTu() va nguong do dai voi laTruyVanThat() nen hai tang
     * do cung mot thu va khong mau thuan nhau.
     */
    private function demTuTrongTen(string $term): int
    {
        $hits = 0;

        foreach (array_unique(SanPham::searchWords($term)) as $word) {
            if (strlen($word) < self::MEANINGFUL_WORD_LENGTH) {
                continue;
            }

            if ($this->tenCoTu($word)) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * Moi tu du dai cua cum cung nam trong ten cua MOT san pham.
     *
     * Day la dieu kien ma commit 5bd1889 hua ("require a real product-name match")
     * nhung chua ship: cong da ship la so sanh tuong doi demTuTrongTen($fixed) >
     * demTuTrongTen($source), nen "Par xe ta mon nam tram" di qua vi 4 > 3.
     *
     * MOT truy van cho ca cum, khong phai N nhu tenCoTu(): cac dieu kien AND
     * chong nhau trong cung mot cau lenh. Khong thay laTruyVanThat() — no co tinh long
     * de "khau trang y te" (0 san pham co ca 4 tu trong TEN) van tim duoc qua
     * ingredients_folded. Cong nay chi quyet dinh CO, khong quyet dinh chuoi tra ve.
     *
     * Do tren catalog that 2026-09-02: ket qua sai 33 -> 14, confident-ma-catalog-rong
     * 15 -> 1, coverage giu nguyen 55/94, 4 ca dung bi ha co oan.
     */
    private function tuCungXuatHien(string $term): bool
    {
        $words = array_unique(array_filter(
            SanPham::searchWords($term),
            fn (string $word) => strlen($word) >= self::MEANINGFUL_WORD_LENGTH
        ));

        if ($words === []) {
            return false;
        }

        $query = SanPham::notExpired();

        foreach ($words as $word) {
            $this->dieuKienTuTrongTen($query, $word);
        }

        return $query->exists();
    }

    /**
     * Cua so 1-N tu lien nhau, dai truoc ngan sau, gop trung qua moi alternative.
     *
     * Be rong va so cua so doc tu config vi phai noi cung nhau: vong lap di dai-truoc
     * nen cap hep tieu het ngan sach ngay o tang dai nhat, xem config/search.php.
     *
     * @return array<string, array{key: string, text: string, source: string}>
     */
    private function cuaSo(array $alternatives): array
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
                    $key = PhienAmTiengViet::khoa($text);

                    // $taken++ SAU khi loai: khoa rong va khoa trung khong sinh ung
                    // vien nao, nen tinh chung vao ngan sach la thu hep vung phu ma
                    // khong duoc gi. Ba alternative cua Web Speech gan giong nhau nen
                    // phan lon cua so la ban sao.
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
     * Mot truy van cho ca tang: khop khit bang phonetic_key, khop gan bang
     * block_key. within() la ham PHP nen bo loc tho phai lam trong SQL, khong thi
     * moi request nap ca index ra bo nho.
     */
    private function traCuu(array $windows): Collection
    {
        $keys = array_keys($windows);
        // Callback tuong minh: array_filter() khong callback bo "0" vi no falsy. Chi
        // xay ra khi ca khoa phien am cua mot cua so la dung mot ky tu "0" (blockKey
        // tra 2 ky tu cho moi chuoi dai hon, va 2 ky tu thi khong bao gio falsy), nen
        // day la bit mot cai bay tiem an chu khong phai mot ca da gap.
        $blocks = array_values(array_unique(array_filter(
            array_map(fn (array $window) => PhienAmTiengViet::khoaKhoi($window['key']), $windows),
            fn (?string $block) => $block !== null && $block !== ''
        )));

        return PhienAmGiongNoi::query()
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
     * Diem chi xep thu tu giua cac ung vien within() DA nhan — khong quyet dinh
     * nhan hay loai. Tron hai viec do lai la cach "vitamin C" thanh "vitamin D".
     */
    private function diem(array $window, PhienAmGiongNoi $candidate): int
    {
        $key = (string) $candidate->phonetic_key;
        $exact = $key === $window['key'];

        return ($exact ? 100 : 100 - PhienAmTiengViet::khoangCach($window['key'], $key) * 12)
            + (self::KIND_BONUS[$candidate->kind] ?? 0)
            + min((int) $candidate->weight, 20);
    }

    private function thayThe(array $window, string $term): string
    {
        $whole = $window['source'];

        if (VietnameseText::fold($whole) === VietnameseText::fold($window['text'])) {
            return $term;
        }

        $replaced = str_ireplace($window['text'], $term, $whole, $count);

        // Cua so duoc sinh tu chuoi da fold nen no khong luon xuat hien nguyen van
        // trong cau goc ("soc" vs "sot"). Khong thay duoc thi dung cum trong catalog:
        // no la phan mang thong tin, phan con lai chi la boi canh.
        return $count > 0 ? $replaced : $term;
    }

    /**
     * Tang cuoi truoc khi tra nguyen van: nho AI chon tu khoa tu danh sach ung vien.
     *
     * Bon van chan, moi van co test rieng: chi chay khi hai tang dau truot, cache
     * 24h, mot khoa lock cho moi cau, va mot circuit breaker. Khong co chung thi
     * 1.000 luot/phut la 1.000 loi goi/phut vao mot provider do duoc mat 18-26s.
     */
    private function hoiAi(array $alternatives): ?string
    {
        if (! (bool) config('search.voice.ai', true) || $this->breakerIsOpen()) {
            return null;
        }

        $cacheKey = 'voice:norm:' . sha1(VietnameseText::fold($alternatives[0]));
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached === self::AI_NO_HELP ? null : $cached;
        }

        // block(3): 100 nguoi noi cung mot cau thi mot nguoi goi, 99 nguoi con lai
        // cho roi doc cache. Bang cache_locks da ton tai nen day la khoa that.
        $lock = Cache::lock($cacheKey . ':lock', 10);

        try {
            $lock->block(3);
        } catch (Throwable) {
            // Khong lay duoc khoa trong 3s: tra nguyen van thay vi xep hang them.
            return null;
        }

        try {
            $fresh = Cache::get($cacheKey);

            if ($fresh !== null) {
                return $fresh === self::AI_NO_HELP ? null : $fresh;
            }

            $answer = $this->doiChieuCatalog($this->goiAi($alternatives));

            Cache::put(
                $cacheKey,
                $answer ?? self::AI_NO_HELP,
                (int) config('search.voice.cache_ttl', 86400)
            );

            return $answer;
        } catch (Throwable $exception) {
            $this->ghiNhanThatBai();

            Log::warning('Voice search AI normalisation failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        } finally {
            $lock->release();
        }
    }

    private function goiAi(array $alternatives): string
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
        $candidates = $this->ungVienChoPrompt($alternatives);

        // Mot dong, trong dau nhay: chuoi nguoi dung noi KHONG duoc tu thanh mot muc
        // trong danh sach. Xuong dong song sot qua catDauCau() (dau cau giua
        // cau thi khong bi cat), nen "abc\nCum co trong catalog gan giong nhat:\n- X"
        // gia duoc dung cai tieu de ma systemPrompt() bao model tin.
        $line = fn (string $value) => '- "' . str_replace(["\r", "\n", '"'], [' ', ' ', "'"], $value) . '"';

        return implode("\n", array_filter([
            'Máy nghe được (tốt nhất trước):',
            ...array_map($line, $alternatives),
            '',
            $candidates === [] ? null : 'Cụm có trong catalog gần giống nhất:',
            ...array_map($line, $candidates),
        ]));
    }

    /**
     * Ung vien cho prompt la chinh nhung dong within() da LOAI o T1 — chung gan ve
     * phien am nhung chua du gan de nhan tu dong. Nho vay mot truy van phuc vu ca
     * hai tang.
     *
     * @return array<int, string>
     */
    private function ungVienChoPrompt(array $alternatives): array
    {
        ['windows' => $windows, 'candidates' => $candidates] = $this->phanDuPhienAm
            ?? ['windows' => $this->cuaSo($alternatives), 'candidates' => null];

        if ($windows === []) {
            return [];
        }

        $candidates ??= $this->traCuu($windows);

        return $candidates
            ->sortByDesc(fn (PhienAmGiongNoi $row) => (self::KIND_BONUS[$row->kind] ?? 0) + min((int) $row->weight, 20))
            ->pluck('term')
            ->unique()
            ->take(self::AI_CANDIDATES)
            ->values()
            ->all();
    }

    /**
     * Luat §2.2 cua spec: chuoi AI tra ve chi duoc dung neu no that su tim ra thu
     * gi trong catalog. Do duoc provider tra "parasitome" cho "paracetamol" — trong
     * app nha thuoc, mot ten thuoc bia khong duoc di toi o tim kiem.
     */
    private function doiChieuCatalog(string $answer): ?string
    {
        $answer = trim(preg_replace('/\s+/', ' ', strip_tags($answer)) ?? '');

        if ($answer === '' || mb_strlen($answer) > 200) {
            return null;
        }

        // laTruyVanThat(), khong phai catalogHas(): AI duoc dua danh sach ung vien nen
        // cau tra loi cua no phai khop catalog tron ven, khong phai khop mot tu. Voi
        // tieu chi long, do duoc provider tra "ba la bu la" va lop kiem nay NHAN, vi
        // chuoi do "tim ra" 125 san pham qua LIKE chuoi con.
        if ($this->laTruyVanThat($answer)) {
            return $answer;
        }

        $key = PhienAmTiengViet::khoa($answer);

        return $key !== '' && PhienAmGiongNoi::where('phonetic_key', $key)->exists() ? $answer : null;
    }

    private function breakerIsOpen(): bool
    {
        return (int) Cache::get('voice:norm:ai_failures', 0)
            >= max(1, (int) config('search.voice.breaker_failures', 5));
    }

    /**
     * Bo dem tu het han theo cua so, nen breaker tu dong lai ma khong can job nao.
     */
    private function ghiNhanThatBai(): void
    {
        $window = (int) config('search.voice.breaker_window', 300);

        // add() truoc roi increment(): increment() tren khoa chua ton tai khong dat
        // duoc TTL, va mot bo dem khong TTL la mot breaker mo vinh vien.
        Cache::add('voice:norm:ai_failures', 0, $window);
        $failures = (int) Cache::increment('voice:norm:ai_failures');
        $threshold = max(1, (int) config('search.voice.breaker_failures', 5));

        // Chi log o dung lan vuot nguong, khong log moi lan that bai sau do: tu day
        // tang AI tat trong $window giay va khong co dau vet nao khac. Truoc day
        // "tang AI dang bi breaker chan" khong phan biet duoc voi "khong cau nao can
        // toi no".
        if ($failures === $threshold) {
            Log::warning('Voice search AI breaker opened', [
                'failures' => $failures,
                'window_seconds' => $window,
            ]);
        }
    }

    /**
     * @return array{text: string, heard: string, source: string, confident: bool}
     */
    private function ketQua(string $text, string $heard, string $source, bool $confident): array
    {
        return [
            'text' => $text,
            'heard' => $heard,
            'source' => $source,
            'confident' => $confident,
        ];
    }
}
