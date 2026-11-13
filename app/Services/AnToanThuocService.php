<?php

namespace App\Services;

use App\Models\ChungChiDinhThuoc;
use App\Models\TuongTacThuoc;
use App\Models\SanPham;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Drug interaction and contraindication checks.
 *
 * All knowledge lives in `drug_interactions` and `drug_contraindications`; nothing
 * here asks a language model. Ingredient names are matched after stripping dosage,
 * because `products.ingredients` stores strings like "Paracetamol 500mg".
 */
class AnToanThuocService
{
    /**
     * Words that appear inside ingredient strings but never identify a drug.
     */
    protected const NOISE_TOKENS = [
        'mg', 'g', 'ml', 'mcg', 'iu', 'vien', 'sui', 'hcl', 'sodium', 'potassium',
        'calcium', 'sulfate', 'besylate', 'maleate', 'tartrate', 'bisulfate',
        'trihydrate', 'oxide', 'carbonate', 'acid', 'va', 'chua', 'tong', 'hop',
    ];

    /**
     * Multi-word ingredient names must survive tokenisation, so they are matched
     * as whole phrases before single tokens are considered.
     */
    protected const COMPOUND_INGREDIENTS = [
        'acid acetylsalicylic',
        'acid ascorbic',
        'acid clavulanic',
        'acid folic',
        'ferrous sulfate',
        'calcium carbonate',
        'magnesium oxide',
        'zinc sulfate',
        'coenzyme q10',
    ];

    public function __construct(
        protected NhatKyKiemToanService $nhatKyKiemToanService,
    ) {
    }

    /**
     * Interaction table indexed by ingredient pair, built once per request.
     *
     * @var array<string, TuongTacThuoc>|null
     */
    protected ?array $chiMucTuongTac = null;

    /**
     * Contraindication rows indexed by ingredient, built once per request.
     *
     * @var array<string, array<int, ChungChiDinhThuoc>>|null
     */
    protected ?array $chiMucChongChiDinh = null;

    /**
     * Every warning that applies to buying this product as this user.
     *
     * @return array{warnings: array<int, array>, blocking: bool}
     */
    public function kiemTraSanPham(SanPham $sanPham, ?HoSoSucKhoe $hoSo = null, ?NguoiDung $nguoiDung = null): array
    {
        $cacHoatChat = $this->trichXuatHoatChat($sanPham->thanh_phan ?? '');

        if ($cacHoatChat === []) {
            return ['warnings' => [], 'blocking' => false];
        }

        $canhBao = [
            ...$this->canhBaoChongChiDinh($cacHoatChat, $hoSo, $nguoiDung),
            ...$this->canhBaoThuocDangDung($cacHoatChat, $hoSo, $sanPham),
            ...$this->canhBaoDiUng($cacHoatChat, $hoSo, $sanPham),
        ];

        return [
            'warnings' => $canhBao,
            'blocking' => $this->coCanhBaoChanChe($canhBao),
        ];
    }

    /**
     * Interaction between two raw ingredient strings (as stored on products).
     */
    public function kiemTraCapHoatChat(string $ingredientsA, string $ingredientsB): Collection
    {
        $a = $this->trichXuatHoatChat($ingredientsA);
        $b = $this->trichXuatHoatChat($ingredientsB);

        if ($a === [] || $b === []) {
            return collect();
        }

        $timThay = collect();

        foreach ($a as $hoatChatA) {
            foreach ($b as $hoatChatB) {
                if ($hoatChatA === $hoatChatB) {
                    continue;
                }

                $tuongTac = $this->timTuongTac($hoatChatA, $hoatChatB);

                if ($tuongTac) {
                    $timThay->push($tuongTac);
                }
            }
        }

        return $timThay->unique('ma_tt')->values();
    }

    /**
     * One query per request instead of one per ingredient pair.
     *
     * A cart of n products with k ingredients each produced O(n²k²) SELECTs — at the
     * 100-item cart ceiling that was ~10k queries for a single checkout render. The
     * curated interaction table is small enough to index in memory once.
     *
     * @return array<string, TuongTacThuoc>
     */
    protected function chiMucTuongTac(): array
    {
        if ($this->chiMucTuongTac !== null) {
            return $this->chiMucTuongTac;
        }

        $chiMuc = [];

        foreach (TuongTacThuoc::active()->get() as $tuongTac) {
            // Stored once but symmetric, so index both directions.
            $chiMuc[$tuongTac->ingredient_a . '|' . $tuongTac->ingredient_b] = $tuongTac;
            $chiMuc[$tuongTac->ingredient_b . '|' . $tuongTac->ingredient_a] = $tuongTac;
        }

        return $this->chiMucTuongTac = $chiMuc;
    }

    protected function timTuongTac(string $a, string $b): ?TuongTacThuoc
    {
        return $this->chiMucTuongTac()[$a . '|' . $b] ?? null;
    }

    /**
     * Same reason as chiMucTuongTac: one query instead of one per product.
     *
     * @return array<string, array<int, ChungChiDinhThuoc>>
     */
    protected function chiMucChongChiDinh(): array
    {
        if ($this->chiMucChongChiDinh !== null) {
            return $this->chiMucChongChiDinh;
        }

        $chiMuc = [];

        foreach (ChungChiDinhThuoc::active()->get() as $dong) {
            $chiMuc[$dong->ingredient][] = $dong;
        }

        return $this->chiMucChongChiDinh = $chiMuc;
    }

    /**
     * Scan the whole cart: every product against the user's profile, and every
     * pair of products against each other.
     *
     * @param array $cartItems session cart rows, each with at least a MaSP key
     * @return array{warnings: array<int, array>, blocking: bool}
     */
    public function kiemTraGioHang(array $cartItems, int $userId): array
    {
        $cacMaSanPham = collect($cartItems)->pluck('ma_sp')->filter()->unique()->values();

        if ($cacMaSanPham->isEmpty()) {
            return ['warnings' => [], 'blocking' => false];
        }

        $sanPhams = SanPham::whereIn('ma_sp', $cacMaSanPham)->get();
        $nguoiDung = NguoiDung::find($userId);
        $hoSo = $this->hoSoDaDongY($userId);

        $canhBao = [];

        foreach ($sanPhams as $sanPham) {
            $canhBao = [...$canhBao, ...$this->kiemTraSanPham($sanPham, $hoSo, $nguoiDung)['warnings']];
        }

        foreach ($this->cacCapSanPham($sanPhams) as [$sanPhamA, $sanPhamB]) {
            foreach ($this->kiemTraCapHoatChat($sanPhamA->thanh_phan ?? '', $sanPhamB->thanh_phan ?? '') as $tuongTac) {
                $canhBao[] = [
                    'type' => 'interaction',
                    'severity' => $tuongTac->severity,
                    'title' => "{$sanPhamA->ten} + {$sanPhamB->ten}",
                    'message' => $tuongTac->description_vi,
                    'action' => $tuongTac->recommendation_vi,
                    'product_ids' => [$sanPhamA->ma_sp, $sanPhamB->ma_sp],
                ];
            }
        }

        return [
            'warnings' => $this->loaiTrung($canhBao),
            'blocking' => $this->coCanhBaoChanChe($canhBao),
        ];
    }

    /**
     * Adding one product to a cart that already holds others.
     *
     * @return array{warnings: array<int, array>, blocking: bool}
     */
    public function kiemTraThemVaoGio(SanPham $sanPham, array $existingCart, int $userId): array
    {
        $nguoiDung = NguoiDung::find($userId);
        $hoSo = $this->hoSoDaDongY($userId);

        $canhBao = $this->kiemTraSanPham($sanPham, $hoSo, $nguoiDung)['warnings'];

        $cacMaDaCo = collect($existingCart)->pluck('ma_sp')->filter()
            ->reject(fn ($id) => (int) $id === (int) $sanPham->ma_sp)
            ->unique();

        if ($cacMaDaCo->isNotEmpty()) {
            foreach (SanPham::whereIn('ma_sp', $cacMaDaCo)->get() as $sanPhamDaCo) {
                foreach ($this->kiemTraCapHoatChat($sanPham->thanh_phan ?? '', $sanPhamDaCo->thanh_phan ?? '') as $tuongTac) {
                    $canhBao[] = [
                        'type' => 'interaction',
                        'severity' => $tuongTac->severity,
                        'title' => "{$sanPham->ten} + {$sanPhamDaCo->ten}",
                        'message' => $tuongTac->description_vi,
                        'action' => $tuongTac->recommendation_vi,
                        'product_ids' => [$sanPham->ma_sp, $sanPhamDaCo->ma_sp],
                    ];
                }
            }
        }

        $canhBao = $this->loaiTrung($canhBao);

        return ['warnings' => $canhBao, 'blocking' => $this->coCanhBaoChanChe($canhBao)];
    }

    /**
     * Called when the user acknowledges a major warning and buys anyway.
     * Recorded so the decision is traceable — this is a health record, not a UX event.
     *
     * The write is best-effort: the safety decision itself has already been made, so a
     * failing audit row must not turn the acknowledged add-to-cart into a 500.
     */
    public function ghiNhatKyBoQua(int $userId, SanPham $sanPham, array $warnings): void
    {
        try {
            $this->nhatKyKiemToanService->ghi(
                action: 'drug_warning_overridden',
                modelType: SanPham::class,
                modelId: $sanPham->ma_sp,
                oldValues: null,
                newValues: [
                    'ma_nd' => $userId,
                    'product' => $sanPham->ten,
                    'warning_types' => collect($warnings)->pluck('type')->unique()->values()->all(),
                    'severities' => collect($warnings)->pluck('severity')->unique()->values()->all(),
                ],
                userId: $userId
            );
        } catch (\Throwable $exception) {
            Log::warning('Drug warning override audit failed', [
                'ma_nd' => $userId,
                'ma_sp' => $sanPham->ma_sp,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Profile is only readable once the user consented; without consent the checks
     * fall back to product-level rules that need no personal data.
     */
    protected function hoSoDaDongY(int $userId): ?HoSoSucKhoe
    {
        return HoSoSucKhoe::consentedFor($userId);
    }

    /**
     * "Amoxicillin 500mg + Acid clavulanic 125mg" -> ['amoxicillin', 'acid clavulanic']
     *
     * @return array<int, string>
     */
    public function trichXuatHoatChat(string $raw): array
    {
        $daChuanHoa = $this->chuanHoaVanBanHoatChat($raw);

        if ($daChuanHoa === '') {
            return [];
        }

        $timThay = [];

        // Compound names first, then remove them so their parts are not re-matched.
        foreach (self::COMPOUND_INGREDIENTS as $compound) {
            if (str_contains($daChuanHoa, $compound)) {
                $timThay[] = $compound;
                $daChuanHoa = str_replace($compound, ' ', $daChuanHoa);
            }
        }

        foreach (preg_split('/\s+/', $daChuanHoa) ?: [] as $token) {
            if ($this->laTokenCoNghia($token)) {
                $timThay[] = $token;
            }
        }

        return array_values(array_unique($timThay));
    }

    /**
     * Same folding rules as NhanDienHinhAnhService::normalizeProductText so both
     * services agree on ingredient spelling.
     */
    protected function chuanHoaVanBanHoatChat(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['đ', 'Đ'], ['d', 'd'], $text);
        $text = Str::ascii($text);
        // Drop the dosage entirely: it is not part of the drug identity.
        $text = preg_replace('/\d+[.,]?\d*\s*(mg|mcg|g|ml|iu)\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    protected function laTokenCoNghia(string $token): bool
    {
        return mb_strlen($token) >= 4
            && !is_numeric($token)
            && !in_array($token, self::NOISE_TOKENS, true);
    }

    /**
     * Age, pregnancy, breastfeeding and chronic-disease contraindications.
     *
     * @return array<int, array>
     */
    protected function canhBaoChongChiDinh(array $ingredients, ?HoSoSucKhoe $hoSo, ?NguoiDung $nguoiDung): array
    {
        $cacDong = collect($ingredients)
            ->flatMap(fn (string $ingredient) => $this->chiMucChongChiDinh()[$ingredient] ?? [])
            ->unique(fn (ChungChiDinhThuoc $dong) => $dong->ma_ccd);

        if ($cacDong->isEmpty()) {
            return [];
        }

        $tuoi = $nguoiDung?->ageInYears();
        $benhManTinh = $hoSo?->hasConsented() ? $this->danhSachChuThuong($hoSo->chronic_conditions) : [];
        $cacDiUng = $hoSo?->hasConsented() ? $this->danhSachChuThuong($hoSo->allergies) : [];

        $canhBao = [];

        foreach ($cacDong as $dong) {
            if (!$this->chongChiDinhGanCho($dong, $tuoi, $hoSo, $benhManTinh, $cacDiUng)) {
                continue;
            }

            $canhBao[] = [
                'type' => 'contraindication',
                'severity' => $dong->isAbsolute() ? 'major' : 'moderate',
                'title' => $this->tieuDeChongChiDinh($dong),
                'message' => $dong->warning_vi,
                'action' => $dong->isAbsolute()
                    ? 'Anh/chị không nên dùng thuốc này. Vui lòng hỏi dược sĩ để chọn loại phù hợp.'
                    : 'Anh/chị nên hỏi dược sĩ trước khi dùng.',
                'ingredient' => $dong->ingredient,
            ];
        }

        return $canhBao;
    }

    protected function chongChiDinhGanCho(
        ChungChiDinhThuoc $dong,
        ?int $tuoi,
        ?HoSoSucKhoe $hoSo,
        array $benhManTinh,
        array $cacDiUng
    ): bool {
        return match ($dong->condition_type) {
            // Unknown age must not silently pass an age guard.
            'age_under' => $tuoi !== null && $tuoi < (int) $dong->condition_value,
            'age_over' => $tuoi !== null && $tuoi > (int) $dong->condition_value,
            'pregnancy' => (bool) ($hoSo?->hasConsented() && $hoSo->is_pregnant),
            'breastfeeding' => (bool) ($hoSo?->hasConsented() && $hoSo->is_breastfeeding),
            'chronic_disease' => $this->danhSachCoDeCap($benhManTinh, (string) $dong->condition_value),
            'allergy' => $this->danhSachCoDeCap($cacDiUng, (string) $dong->condition_value),
            default => false,
        };
    }

    protected function tieuDeChongChiDinh(ChungChiDinhThuoc $dong): string
    {
        return match ($dong->condition_type) {
            'age_under' => "Không phù hợp với người dưới {$dong->condition_value} tuổi",
            'age_over' => "Cần thận trọng với người trên {$dong->condition_value} tuổi",
            'pregnancy' => 'Cần thận trọng khi mang thai',
            'breastfeeding' => 'Cần thận trọng khi cho con bú',
            'chronic_disease' => "Cần thận trọng với tình trạng: {$dong->condition_value}",
            'allergy' => "Liên quan dị ứng đã khai: {$dong->condition_value}",
            default => 'Cảnh báo chống chỉ định',
        };
    }

    /**
     * Interaction between the product being bought and what the user already takes.
     *
     * @return array<int, array>
     */
    protected function canhBaoThuocDangDung(array $ingredients, ?HoSoSucKhoe $hoSo, SanPham $sanPham): array
    {
        if (!$hoSo?->hasConsented() || empty($hoSo->current_medications)) {
            return [];
        }

        $canhBao = [];

        foreach ($hoSo->current_medications as $thuocDangDung) {
            foreach ($this->trichXuatHoatChat((string) $thuocDangDung) as $hoatChatDaCo) {
                foreach ($ingredients as $hoatChatMoi) {
                    if ($hoatChatMoi === $hoatChatDaCo) {
                        continue;
                    }

                    $tuongTac = $this->timTuongTac($hoatChatMoi, $hoatChatDaCo);

                    if (!$tuongTac) {
                        continue;
                    }

                    $canhBao[] = [
                        'type' => 'current_medication',
                        'severity' => $tuongTac->severity,
                        'title' => "{$sanPham->ten} + thuốc đang dùng ({$thuocDangDung})",
                        'message' => $tuongTac->description_vi,
                        'action' => $tuongTac->recommendation_vi,
                        'ingredient' => $hoatChatMoi,
                    ];
                }
            }
        }

        return $canhBao;
    }

    /**
     * Direct allergy match. Always blocking: a declared allergy is not a suggestion.
     *
     * @return array<int, array>
     */
    protected function canhBaoDiUng(array $ingredients, ?HoSoSucKhoe $hoSo, SanPham $sanPham): array
    {
        if (!$hoSo?->hasConsented() || empty($hoSo->allergies)) {
            return [];
        }

        $canhBao = [];

        foreach ($hoSo->allergies as $diUng) {
            $cacTokenDiUng = $this->trichXuatHoatChat((string) $diUng);
            $diUngDaChuanHoa = $this->chuanHoaVanBanHoatChat((string) $diUng);
            $tenSanPham = $this->chuanHoaVanBanHoatChat($sanPham->ten);

            // The name fallback needs the same length floor as laTokenCoNghia:
            // an allergy typed as "C" or "ca" would otherwise substring-match nearly
            // every product name and block the whole catalog as a major warning.
            $khopTheoTen = mb_strlen($diUngDaChuanHoa) >= 4
                && preg_match('/(?<![a-z0-9])' . preg_quote($diUngDaChuanHoa, '/') . '/u', $tenSanPham) === 1;

            $daKhop = array_intersect($cacTokenDiUng, $ingredients) !== [] || $khopTheoTen;

            if (!$daKhop) {
                continue;
            }

            $canhBao[] = [
                'type' => 'allergy',
                'severity' => 'major',
                'title' => "Anh/chị đã khai dị ứng với: {$diUng}",
                'message' => "Sản phẩm {$sanPham->ten} có thành phần trùng hoặc liên quan đến chất anh/chị đã khai dị ứng.",
                'action' => 'Anh/chị không nên dùng thuốc này. Vui lòng hỏi dược sĩ để được đổi sang thuốc khác.',
                'ingredient' => $diUng,
            ];
        }

        return $canhBao;
    }

    /**
     * @return array<int, array{0: SanPham, 1: SanPham}>
     */
    protected function cacCapSanPham(Collection $sanPhams): array
    {
        $danhSach = $sanPhams->values();
        $cacCap = [];

        for ($i = 0; $i < $danhSach->count(); $i++) {
            for ($j = $i + 1; $j < $danhSach->count(); $j++) {
                $cacCap[] = [$danhSach[$i], $danhSach[$j]];
            }
        }

        return $cacCap;
    }

    protected function coCanhBaoChanChe(array $warnings): bool
    {
        return collect($warnings)->contains(fn (array $warning) => ($warning['severity'] ?? '') === 'major');
    }

    protected function loaiTrung(array $warnings): array
    {
        return collect($warnings)
            ->unique(fn (array $warning) => ($warning['type'] ?? '') . '|' . ($warning['title'] ?? ''))
            ->sortBy(fn (array $warning) => match ($warning['severity'] ?? 'minor') {
                'major' => 1,
                'moderate' => 2,
                default => 3,
            })
            ->values()
            ->all();
    }

    protected function danhSachChuThuong(?array $items): array
    {
        return collect($items ?? [])
            ->map(fn ($item) => mb_strtolower(trim((string) $item), 'UTF-8'))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Substring match in both directions: the user may write "benh da day" where
     * the rule says "loet da day", or the other way round.
     */
    protected function danhSachCoDeCap(array $haystack, string $needle): bool
    {
        if ($needle === '' || $haystack === []) {
            return false;
        }

        $needle = mb_strtolower(trim($needle), 'UTF-8');
        $needleAscii = $this->chuanHoaVanBanHoatChat($needle);

        foreach ($haystack as $muc) {
            $mucAscii = $this->chuanHoaVanBanHoatChat($muc);

            if ($mucAscii === '' || $needleAscii === '') {
                continue;
            }

            if (str_contains($mucAscii, $needleAscii) || str_contains($needleAscii, $mucAscii)) {
                return true;
            }
        }

        return false;
    }
}
