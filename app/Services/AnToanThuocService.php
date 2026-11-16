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
 * Kiểm tra tương tác thuốc và chống chỉ định.
 *
 * Toàn bộ kiến thức nằm trong `drug_interactions` và `drug_contraindications`; không
 * chỗ nào ở đây hỏi một language model. Tên hoạt chất được khớp sau khi bỏ liều lượng,
 * vì `products.ingredients` lưu chuỗi như "Paracetamol 500mg".
 */
class AnToanThuocService
{
    /**
     * Các từ xuất hiện trong chuỗi thành phần nhưng không bao giờ xác định một thuốc.
     */
    protected const NOISE_TOKENS = [
        'mg', 'g', 'ml', 'mcg', 'iu', 'vien', 'sui', 'hcl', 'sodium', 'potassium',
        'calcium', 'sulfate', 'besylate', 'maleate', 'tartrate', 'bisulfate',
        'trihydrate', 'oxide', 'carbonate', 'acid', 'va', 'chua', 'tong', 'hop',
    ];

    /**
     * Tên hoạt chất nhiều từ phải sống sót qua bước tách token, nên chúng được khớp
     * như nguyên cụm trước khi xét đến từng token đơn lẻ.
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
     * Bảng tương tác index theo cặp hoạt chất, dùng một lần mỗi request.
     *
     * @var array<string, TuongTacThuoc>|null
     */
    protected ?array $chiMucTuongTac = null;

    /**
     * Các dòng chống chỉ định index theo hoạt chất, dùng một lần mỗi request.
     *
     * @var array<string, array<int, ChungChiDinhThuoc>>|null
     */
    protected ?array $chiMucChongChiDinh = null;

    /**
     * Mọi cảnh báo áp dụng cho việc mua sản phẩm này với người dùng này.
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
     * Tương tác giữa hai chuỗi hoạt chất thiếu (như cách lưu trên products).
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
     * Một truy vấn mỗi request thay vì một truy vấn cho mỗi cặp hoạt chất.
     *
     * Giỏ hàng n sản phẩm mỗi sản phẩm k hoạt chất đã tạo ra O(n²k²) SELECT — ở
     * trần 100 sản phẩm, đó là ~10k truy vấn cho một lần render checkout. Bảng tương tác
     * được biên soạn nhỏ đủ để index trong bộ nhớ một lần.
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
            // Lưu một lần nhưng đối xứng, nên index cả hai chiều.
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
     * Cùng lý do với chiMucTuongTac: một truy vấn thay vì một truy vấn cho mỗi sản phẩm.
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
     * Quét cả giỏ hàng: mỗi sản phẩm đối chiếu với hồ sơ người dùng, và mỗi cặp
     * sản phẩm đối chiếu với nhau.
     *
     * @param array $cartItems các dòng giỏ hàng trong session, mỗi dòng ít nhất có key MaSP
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
     * Thêm một sản phẩm vào giỏ hàng đã có sản phẩm khác.
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
     * Được gọi khi người dùng xác nhận một cảnh báo major và vẫn mua.
     * Ghi lại để quyết định có thể truy vết — đây là bản ghi sức khoẻ, không phải sự kiện UX.
     *
     * Việc ghi là best-effort: quyết định an toàn đã được đưa ra, nên một dòng audit
     * ghi thất bại không được biến việc thêm vào giỏ đã xác nhận thành lỗi 500.
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
     * Hồ sơ chỉ được đọc sau khi người dùng đồng ý; không có đồng ý, các kiểm tra
     * quay về quy tắc cấp sản phẩm không cần dữ liệu cá nhân.
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

        // Tên gộp trước, rồi xoá chúng để các phần của chúng không bị khớp lại.
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
     * Cùng quy tắc fold với NhanDienHinhAnhService::normalizeProductText để cả hai
     * service đồng nhất về cách viết hoạt chất.
     */
    protected function chuanHoaVanBanHoatChat(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['đ', 'Đ'], ['d', 'd'], $text);
        $text = Str::ascii($text);
        // Bỏ hoàn toàn liều lượng: nó không thuộc về danh tính của thuốc.
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
     * Chống chỉ định về tuổi, mang thai, cho con bú và bệnh mạn tính.
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
            // Tuổi không biết không được im lặng vượt qua guard tuổi.
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
     * Tương tác giữa sản phẩm đang mua và những gì người dùng đang dùng.
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
     * Khớp đúng dị ứng đã khai. Luôn chặn: dị ứng đã khai không phải lỗi để xuất.
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

            // Fallback theo tên cần cùng sẵn độ dài tối thiểu với laTokenCoNghia:
            // một dị ứng được gõ như "C" hoặc "ca" nếu không sẽ substring-khớp gần
            // mọi tên sản phẩm và chặn cả catalog như một cảnh báo major.
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
     * Khớp substring theo cả hai chiều: người dùng có thể viết "bệnh dạ dày" trong khi
     * quy tắc ghi "loét dạ dày", hoặc ngược lại.
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
