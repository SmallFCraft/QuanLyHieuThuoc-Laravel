<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\PhienAmGiongNoi;
use App\Support\PhienAmTiengViet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Index phiên âm là bảng tra cứu dựng lại từ catalog, không phải dữ liệu nghiệp
 * vụ. Nó phải dựng lại được bất cứ lúc nào và cho cùng một kết quả.
 */
class XayDungPhienAmGiongNoiTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(DanhMuc $danhMuc, string $name): SanPham
    {
        return SanPham::create(['ma_dm' => $danhMuc->ma_dm,
            'ten' => $name,
            'thanh_phan' => 'Paracetamol 500mg',
            'gia' => 50000,
            'ngay_het_han' => now()->addYears(2),
            'so_luong_ton' => 30,
            'muc_canh_bao' => 10,
            'so_lo' => 'VP-' . md5($name),
        ]);
    }

    public function test_lap_chi_muc_ten_san_pham_danh_muc_va_tu_pho_bien(): void
    {
        $danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau, hạ sốt']);

        // "thuốc" xuất hiện 3 lần -> đạt ngưỡng; "Ceradan" chỉ 1 lần -> không.
        $this->makeProduct($danhMuc, 'Thuốc hạ sốt Hapacol 500');
        $this->makeProduct($danhMuc, 'Thuốc hạ sốt Efferalgan 500');
        $this->makeProduct($danhMuc, 'Thuốc giảm đau Panadol Extra');
        $this->makeProduct($danhMuc, 'Kem dưỡng ẩm Ceradan');

        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertDatabaseHas('phien_am_giong_nois', [
            'term' => 'Thuốc hạ sốt Hapacol 500',
            'kind' => 'product',
        ]);
        $this->assertDatabaseHas('phien_am_giong_nois', [
            'term' => 'Thuốc giảm đau, hạ sốt',
            'kind' => 'category',
        ]);
        $this->assertTrue(
            PhienAmGiongNoi::where('kind', 'token')->where('term_folded', 'thuoc')->exists(),
            'token xuất hiện >= 3 lần phải vào index'
        );
        $this->assertFalse(
            PhienAmGiongNoi::where('kind', 'token')->where('term_folded', 'ceradan')->exists(),
            'token xuất hiện 1 lần không được vào index'
        );
    }

    public function test_chay_lap_lai_khong_doi_ket_qua(): void
    {
        $danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau, hạ sốt']);
        $this->makeProduct($danhMuc, 'Thuốc hạ sốt Hapacol 500');

        $this->artisan('voice:build-phonetics')->assertSuccessful();
        $first = PhienAmGiongNoi::count();

        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertSame($first, PhienAmGiongNoi::count());
    }

    public function test_khoa_dai_khong_co_khoa_khoi(): void
    {
        $danhMuc = DanhMuc::create(['ten' => 'Thuốc gan']);
        $long = 'Thuốc Silymax-F Hưng Việt điều trị viêm gan do virus, xơ gan, gan nhiễm mỡ';
        $this->makeProduct($danhMuc, $long);

        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertNull(PhienAmGiongNoi::where('term', $long)->value('block_key'));
        $this->assertNotNull(
            PhienAmGiongNoi::where('kind', 'token')->whereNotNull('block_key')->first(),
            'cụm ngắn phải có block_key'
        );
    }

    /**
     * MySQL mặc định utf8mb4_0900_ai_ci không phân biệt hoa/thường, mà khoá phiên
     * âm dùng chữ hoa để mã âm gộp. Đo được 65 nhóm khoá bị nhập nếu để mặc định.
     */
    public function test_khoa_chi_khac_hoa_thuong_la_cac_dong_rieng(): void
    {
        PhienAmGiongNoi::insert([
            ['phonetic_key' => 'thUT', 'block_key' => 'th', 'term' => 'thuốc', 'term_folded' => 'thuoc', 'kind' => 'token', 'weight' => 1],
            ['phonetic_key' => 'thuT', 'block_key' => 'th', 'term' => 'thut', 'term_folded' => 'thut', 'kind' => 'token', 'weight' => 1],
        ]);

        $this->assertSame(2, PhienAmGiongNoi::count());
        $this->assertSame(1, PhienAmGiongNoi::whereIn('phonetic_key', ['thUT'])->count());
        $this->assertSame(0, PhienAmGiongNoi::whereIn('phonetic_key', ['thut'])->count());
    }

    public function test_danh_muc_rong_tao_chi_muc_rong(): void
    {
        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertSame(0, PhienAmGiongNoi::count());
    }

    public function test_san_pham_moi_xuat_hien_sau_khi_xay_dung_lai(): void
    {
        $danhMuc = DanhMuc::create(['ten' => 'Thuốc ho']);
        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->makeProduct($danhMuc, 'Siro ho Prospan');
        $this->artisan('voice:build-phonetics')->assertSuccessful();

        $this->assertDatabaseHas('phien_am_giong_nois', [
            'term' => 'Siro ho Prospan',
            'phonetic_key' => PhienAmTiengViet::khoa('Siro ho Prospan'),
        ]);
    }
}
