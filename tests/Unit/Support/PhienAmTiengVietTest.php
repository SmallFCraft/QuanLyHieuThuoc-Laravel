<?php

namespace Tests\Unit\Support;

use App\Support\PhienAmTiengViet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Khoá phiên âm gộp các cặp âm lệch giữa ba vùng miền, nên "thuấc hạ sốc" và
 * "thuốc hạ sốt" phải cho cùng một khoá. Số trong test này là số đã đo, không
 * phải kỳ vọng — xem §4.2 và §4.3 của spec.
 */
class PhienAmTiengVietTest extends TestCase
{
    /** Cách đọc lệch vùng miền phải được nhận. */
    public static function regionalPairs(): array
    {
        return [
            'coda t/c (Nam)' => ['thuoc ha sot', 'thuấc hạ sốc'],
            'onset tr/ch' => ['khau trang', 'khẩu chang'],
            'onset s/x' => ['men vi sinh', 'mem vi xinh'],
            'nhiều âm tiết' => ['nuoc muoi sinh ly', 'nước muối xinh lý'],
            'coda n/ng' => ['kem chong nang', 'kem chóng nắng'],
            'đọc rời âm tiết' => ['paracetamol', 'pa ra xe ta mon'],
            'x giữa từ' => ['canxi', 'can xi'],
            'onset r/d' => ['siro ho', 'si rô hô'],
            'onset ph/f' => ['ibuprofen', 'i bu prô phen'],
            'onset gi/d' => ['collagen', 'cô la gien'],
            'số đọc thành chữ' => ['omega 3', 'ô mê ga ba'],
        ];
    }

    /** Cách đọc lệch vùng miền phải được nhận. */
    #[DataProvider('regionalPairs')]
    public function test_cach_doc_lech_vung_mien_duoc_chap_nhan(string $catalog, string $spoken): void
    {
        $this->assertTrue(
            PhienAmTiengViet::ganNhau(PhienAmTiengViet::khoa($catalog), PhienAmTiengViet::khoa($spoken)),
            "\"{$spoken}\" phải khớp \"{$catalog}\""
        );
    }

    /**
     * Hai sản phẩm khác nhau không bao giờ được gộp. Danh sách gồm những tên
     * chỉ khác nhau một chữ, vốn là chỗ mọi ngưỡng Levenshtein đơn đã sai.
     */
    public function test_hai_san_pham_khac_nhau_khong_bao_gio_gop(): void
    {
        $names = [
            'paracetamol', 'panadol', 'ibuprofen', 'amoxicillin',
            'vitamin c', 'vitamin d', 'vitamin e', 'vitamin b1', 'vitamin b6',
            'canxi', 'collagen', 'omega 3', 'men vi sinh', 'khau trang',
            'kem chong nang', 'berberin', 'smecta', 'oresol', 'efferalgan',
            'hapacol', 'decolgen', 'tiffy', 'strepsils', 'dorithricin',
            'betadine', 'povidine',
        ];

        foreach ($names as $i => $left) {
            foreach (array_slice($names, $i + 1) as $right) {
                $this->assertFalse(
                    PhienAmTiengViet::ganNhau(PhienAmTiengViet::khoa($left), PhienAmTiengViet::khoa($right)),
                    "\"{$left}\" và \"{$right}\" là hai sản phẩm khác nhau"
                );
            }
        }
    }

    /** Âm tiết một-hai ký tự mang toàn bộ nghĩa phân biệt, nên nó phải khít. */
    public function test_am_tiet_nguyen_am_du_lam_phai_khit(): void
    {
        $this->assertFalse(PhienAmTiengViet::ganNhau('DitamiN K', 'DitamiN D'));
        $this->assertTrue(PhienAmTiengViet::ganNhau('DitamiN K', 'DitamiN K'));
    }

    /**
     * Hàm lượng là dữ liệu định lượng, không phải cách phát âm — không giọng
     * miền nào đọc 500 thành 50.
     */
    public static function dosagePairs(): array
    {
        return [
            '500mg vs 50mg' => ['500mg', '50mg', false],
            '50mg vs 500mg (đối xứng)' => ['50mg', '500mg', false],
            '5ml vs 50ml' => ['5ml', '50ml', false],
            'siro 5ml vs siro 50ml' => ['siro ho 5ml', 'siro ho 50ml', false],
            'canxi 500mg vs canxi 50mg' => ['canxi 500mg', 'canxi 50mg', false],
            'vitamin b1 vs vitamin b6' => ['vitamin b1', 'vitamin b6', false],
            '10 vien vs 100 vien' => ['10 vien', '100 vien', false],
            '500mg khít' => ['500mg', '500mg', true],
            'paracetamol 500mg khít' => ['paracetamol 500mg', 'paracetamol 500mg', true],
        ];
    }

    /**
     * Đo trên bản trước bản sửa: `within(key('500mg'), key('50mg'))` trả TRUE.
     * Hai khoá lệch đúng 1 ký tự, mà nhánh "cùng số âm tiết" cấp ngân sách
     * `max(1, intdiv(5, 4)) = 1`, tức luôn ≥1 — nên 500mg gộp với 50mg, sai
     * liều 10 lần. `canxi 500mg` / `canxi 50mg` và `siro ho 5ml` /
     * `siro ho 50ml` cũng vậy (lev 1, ngưỡng 2). Cùng họ lỗi với
     * `vitamin c` ≡ `vitamin d` mà §4.3 đã chặn: chỗ phân biệt sản phẩm không
     * được cấp ngân sách lệch nào.
     */
    #[DataProvider('dosagePairs')]
    public function test_chu_so_ham_luong_phai_khit(string $left, string $right, bool $accepted): void
    {
        $result = PhienAmTiengViet::ganNhau(PhienAmTiengViet::khoa($left), PhienAmTiengViet::khoa($right));

        if ($accepted) {
            $this->assertTrue($result, "\"{$left}\" và \"{$right}\" cùng hàm lượng, phải nhận");
        } else {
            $this->assertFalse($result, "\"{$left}\" và \"{$right}\" khác hàm lượng, không được gộp");
        }
    }

    /**
     * Ranh giới giữa "chữ số phải khít" và "chữ số đọc thành chữ": ở đây một
     * bên có chữ số, bên kia không, vì người nói đọc "3" thành "ba". Cặp này
     * đi qua nhánh "khác số âm tiết" (2 âm tiết vs 4), nơi chỉ khoá phẳng so
     * được — luật chữ số không được với tới nó, nếu không §4.2 mất một cặp.
     */
    public function test_chu_so_doc_thanh_chu_van_duoc_chap_nhan(): void
    {
        $this->assertTrue(
            PhienAmTiengViet::ganNhau(PhienAmTiengViet::khoa('omega 3'), PhienAmTiengViet::khoa('ô mê ga ba')),
            '"ô mê ga ba" phải khớp "omega 3"'
        );
    }

    /**
     * levenshtein() của PHP đếm byte. Khoá chỉ chứa ASCII nên byte ≡ ký tự;
     * một ký tự đa byte lọt vào khoá sẽ làm mọi ngưỡng lệch âm thầm.
     */
    public function test_khoa_chi_chua_ascii(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9 ]*$/',
            PhienAmTiengViet::khoa('Thuốc hạ sốt 500mg — trẻ em!')
        );
    }

    /** Hoa/thường là thông tin: "thuốc" và "thut" là hai âm khác nhau. */
    public function test_hoa_thuong_mang_y_nghia(): void
    {
        $this->assertSame('thUT', PhienAmTiengViet::khoa('thuoc'));
        $this->assertSame('thuT', PhienAmTiengViet::khoa('thut'));
        $this->assertNotSame(PhienAmTiengViet::khoa('thuoc'), PhienAmTiengViet::khoa('thut'));
    }

    /** Nguyên âm đôi phải sống sót qua bước xử lý âm cuối. */
    public function test_van_song_sot_qua_thay_the_am_cuoi(): void
    {
        $this->assertStringContainsString('U', PhienAmTiengViet::khoa('thuoc'));
    }

    /**
     * Bảng §4.1 xếp `oi`, `uôi` → `O`, nhưng luật `uo` → `U` chạy trước nên nó
     * ăn hết "uoi" và luật `uoi` không bao giờ tới lượt. Đo trên bản trước bản
     * sửa: `key('muoi')` = 'mUi' trong khi `key('mói')` = 'mO', nên "nước mói
     * sinh lý" (giọng lệch) không khớp "nước muối sinh lý". Cùng họ lỗi mà
     * docblock của `syllable()` đã cảnh báo cho âm cuối, chỉ là chưa áp cho
     * chính các luật rime.
     */
    public function test_van_uoi_uu_tien_truoc_uo(): void
    {
        $this->assertSame('mO', PhienAmTiengViet::khoa('muoi'));
        $this->assertSame('mO', PhienAmTiengViet::khoa('muối'));
        $this->assertSame('mO', PhienAmTiengViet::khoa('mói'));

        // `uo` phải còn nguyên tác dụng khi không có `i` theo sau.
        $this->assertSame('thUT', PhienAmTiengViet::khoa('thuoc'));

        $this->assertTrue(
            PhienAmTiengViet::ganNhau(
                PhienAmTiengViet::khoa('nuoc muoi sinh ly'),
                PhienAmTiengViet::khoa('nước mói sinh lý')
            ),
            '"nước mói sinh lý" phải khớp "nước muối sinh lý"'
        );
    }

    /**
     * `block_key` là bộ lọc thô chạy trong SQL, `within()` mới là bộ lọc chính
     * xác chạy trong PHP — bỏ hoa/thường ở tầng thô chỉ nới rộng tập ứng viên.
     * Đo trên bản trước bản sửa: `key('tiu')` = 'tiu' cho block 'ti' còn
     * `key('tiêu')` = 'tIu' cho block 'tI', nên `orWhereIn('block_key', …)`
     * không bao giờ nạp ra ứng viên mà `within()` sẽ nhận ("thuốc tiu hóa").
     *
     * Chiều gộp được chốt về chữ thường: cột `block_key` dùng collation
     * `utf8mb4_bin`, nên bên ghi index và bên truy vấn phải cho cùng một chuỗi
     * byte. `phonetic_key` thì vẫn phân biệt hoa/thường — xem
     * test_case_carries_meaning.
     */
    public function test_khoa_khoi_khong_phan_biet_hoa_thuong(): void
    {
        $this->assertSame('ti', PhienAmTiengViet::khoaKhoi(PhienAmTiengViet::khoa('tiu')));
        $this->assertSame('ti', PhienAmTiengViet::khoaKhoi(PhienAmTiengViet::khoa('tiêu')));

        $this->assertTrue(
            PhienAmTiengViet::ganNhau(PhienAmTiengViet::khoa('tiu'), PhienAmTiengViet::khoa('tiêu')),
            'within() nhận cặp này, nên tầng thô không được loại nó trước'
        );
    }

    public function test_khoa_khoi_gom_cac_bien_the_vung_mien(): void
    {
        foreach (self::regionalPairs() as $label => [$catalog, $spoken]) {
            $this->assertSame(
                PhienAmTiengViet::khoaKhoi(PhienAmTiengViet::khoa($catalog)),
                PhienAmTiengViet::khoaKhoi(PhienAmTiengViet::khoa($spoken)),
                "block_key phải trùng cho: {$label}"
            );
        }
    }

    /** Tên sản phẩm đầy đủ không phải ứng viên khớp gần của một câu nói ngắn. */
    public function test_khoa_khoi_null_voi_khoa_dai(): void
    {
        $long = 'Thuốc Silymax-F Hưng Việt điều trị viêm gan do virus, xơ gan, gan nhiễm mỡ';

        $this->assertNull(PhienAmTiengViet::khoaKhoi(PhienAmTiengViet::khoa($long)));
        $this->assertSame('th', PhienAmTiengViet::khoaKhoi(PhienAmTiengViet::khoa('thuoc ha sot')));
    }

    public function test_dau_vao_rong_va_le_lai_khong_loi(): void
    {
        $this->assertSame('', PhienAmTiengViet::khoa(''));
        $this->assertSame('', PhienAmTiengViet::khoa('!!! ??? ---'));
        $this->assertSame('500', PhienAmTiengViet::khoa('500'));
        $this->assertFalse(PhienAmTiengViet::ganNhau('', ''));
        $this->assertNull(PhienAmTiengViet::khoaKhoi(''));
    }

    /**
     * "bơ bơ rin" cho "berberin" cố ý trượt: nó xa quá mức một bảng gộp âm
     * chịu được, và tầng AI tồn tại đúng cho những trường hợp đó.
     */
    public function test_cach_doc_lech_qua_xa_danh_cho_tang_ai(): void
    {
        $this->assertFalse(
            PhienAmTiengViet::ganNhau(PhienAmTiengViet::khoa('berberin'), PhienAmTiengViet::khoa('bơ bơ rin'))
        );
    }

    /**
     * Tên thương hiệu đọc rời từng âm: `syllable()` chỉ áp bảng §4.1 ở đầu từ,
     * nên bên đọc rời thu được luật một lần mỗi âm tiết còn bên catalog nối liền
     * chỉ thu một lần. Đo được: `key('pa na đôn')` = 'pa La DoN' so với
     * 'panadol', lev 3 trên ngân sách 2 — trượt vì cách mã hoá, không vì hai
     * cách đọc thật sự xa nhau.
     */
    public static function spelledOutBrands(): array
    {
        return [
            'panadol' => ['panadol', 'pa na đôn'],
            'salonpas' => ['salonpas', 'sa lon pát'],
            'nizoral' => ['nizoral', 'ni giô ran'],
            'collagen' => ['collagen', 'côn la gien'],
        ];
    }

    #[DataProvider('spelledOutBrands')]
    public function test_ten_thuong_hieu_doc_roi_tung_am_duoc_chap_nhan(string $catalog, string $spoken): void
    {
        $this->assertTrue(
            PhienAmTiengViet::ganNhau(PhienAmTiengViet::khoa($catalog), PhienAmTiengViet::khoa($spoken)),
            "\"{$spoken}\" phải khớp \"{$catalog}\""
        );
    }

    /**
     * Bước thô của nhánh "khác số âm tiết" không được mua thêm recall bằng an
     * toàn. Cặp gần nhất phải giữ khoảng cách là "vitamin e" / "betadine": lev 4
     * trên 8 ký tự, đúng tỉ lệ của những cách đọc thương hiệu ở trên — nên
     * không ngưỡng nào tách được hai nhóm, và đây là lý do bước thô đổi *dạng*
     * so sánh chứ không nới ngân sách.
     */
    public function test_buoc_tho_giu_cac_san_pham_khac_nhau_tach_roi(): void
    {
        $this->assertFalse(
            PhienAmTiengViet::ganNhau(PhienAmTiengViet::khoa('vitamin e'), PhienAmTiengViet::khoa('betadine'))
        );
        $this->assertFalse(
            PhienAmTiengViet::ganNhau(PhienAmTiengViet::khoa('panadol'), PhienAmTiengViet::khoa('pa ra xe ta mon')),
            '"pa ra xe ta mon" là paracetamol, không phải panadol'
        );
        $this->assertFalse(
            PhienAmTiengViet::ganNhau(PhienAmTiengViet::khoa('povidine'), PhienAmTiengViet::khoa('bê tha đin')),
            '"bê tha đin" là betadine, không phải povidine'
        );
    }
}
