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
 * Kiem tra tuong tac thuoc va chong chi dinh.
 *
 * Toan bo kien thuc nam trong `drug_interactions` va `drug_contraindications`; khong
 * cho nao o day hoi mot language model. Ten hoat chat duoc khop sau khi bo lieu luong,
 * vi `products.ingredients` luu chuoi nhu "Paracetamol 500mg".
 */
class AnToanThuocService
{
    /**
     * Cac tu xuat hien trong chuoi thanh phan nhung khong bao gio xac dinh mot thuoc.
     */
    protected const NOISE_TOKENS = [
        'mg', 'g', 'ml', 'mcg', 'iu', 'vien', 'sui', 'hcl', 'sodium', 'potassium',
        'calcium', 'sulfate', 'besylate', 'maleate', 'tartrate', 'bisulfate',
        'trihydrate', 'oxide', 'carbonate', 'acid', 'va', 'chua', 'tong', 'hop',
    ];

    /**
     * Ten hoat chat nhieu tu phai song sot qua buoc tach token, nen chung duoc khop
     * nhu nguyen cum truoc khi xet den tung token don le.
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
     * Bang tuong tac index theo cap hoat chat, dung mot lan moi request.
     *
     * @var array<string, TuongTacThuoc>|null
     */
    protected ?array $chiMucTuongTac = null;

    /**
     * Cac dong chong chi dinh index theo hoat chat, dung mot lan moi request.
     *
     * @var array<string, array<int, ChungChiDinhThuoc>>|null
     */
    protected ?array $chiMucChongChiDinh = null;

    /**
     * Moi canh bao ap dung cho viec mua san pham nay voi nguoi dung nay.
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
     * Tuong tac giua hai chuoi hoat chat thieu (nhu cach luu tren products).
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
     * Mot truy van moi request thay vi mot truy van cho moi cap hoat chat.
     *
     * Gio hang n san pham moi san pham k hoat chat da tao ra O(n²k²) SELECT — o
     * tran 100 san pham, do la ~10k truy van cho mot lan render checkout. Bang tuong tac
     * duoc bien soan nho du de index trong bo nho mot lan.
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
            // Luu mot lan nhung doi xung, nen index ca hai chieu.
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
     * Cung ly do voi chiMucTuongTac: mot truy van thay vi mot truy van cho moi san pham.
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
     * Quet ca gio hang: moi san pham doi chieu voi ho so nguoi dung, va moi cap
     * san pham doi chieu voi nhau.
     *
     * @param array $cartItems cac dong gio hang trong session, moi dong it nha co key MaSP
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
     * Them mot san pham vao gio hang da co san pham khac.
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
     * Duoc goi khi nguoi dung xac nhan mot canh bao major va van mua.
     * Ghi lai de quyet dinh co the truy vet — day la ban ghi suc khoe, khong phai su kien UX.
     *
     * Viec ghi la best-effort: quyet dinh an toan da duoc dua ra, nen mot dong audit
     * ghi that bai khong duoc bien viec them vao gio da xac nhan thanh loi 500.
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
     * Ho so chi duoc doc sau khi nguoi dung dong y; khong co dong y, cac kiem tra
     * quay ve quy tac cap san pham khong can du lieu ca nhan.
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

        // Ten gop truoc, roi xoa chung de cac phan cua chung khong bi khop lai.
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
     * Cung quy tac fold voi NhanDienHinhAnhService::normalizeProductText de ca hai
     * service dong nhat ve cach viet hoat chat.
     */
    protected function chuanHoaVanBanHoatChat(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['đ', 'Đ'], ['d', 'd'], $text);
        $text = Str::ascii($text);
        // Bo hoan toan lieu luong: no khong thuoc ve danh tinh cua thuoc.
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
     * Chong chi dinh ve tuoi, mang thai, cho con bu va benh man tinh.
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
            // Tuoi khong biet khong duoc im lang vuot qua guard tuoi.
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
     * Tuong tac giua san pham dang mua va nhung gi nguoi dung dang dung.
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
     * Khop dung dung vi di ung da khai. Luon chan: di ung da khai khong phai loi de xuat.
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

            // Fallback theo ten can cung san do dai toi thieu voi laTokenCoNghia:
            // mot di ung duoc go nhu "C" hoac "ca" neu khong se substring-khop gan
            // moi ten san pham va chan ca catalog nhu mot canh bao major.
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
     * Khop substring theo ca hai chieu: nguoi dung co the viet "benh da day" trong khi
     * quy tac ghi "loet da day", hoac nguoc lai.
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
