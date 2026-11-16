<?php

namespace Tests\Unit\Services;

use App\Models\DanhMuc;
use App\Models\ChungChiDinhThuoc;
use App\Models\SanPham;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use App\Services\AnToanThuocService;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnToanThuocServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AnToanThuocService $service;
    protected DanhMuc $danhMuc;
    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        $this->danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->service = app(AnToanThuocService::class);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(34)->toDateString(),
        ]);
    }

    // --- Ingredient extraction ---

    public function test_lieu_duoc_bo_khoi_ten_hoat_chat(): void
    {
        $this->assertSame(['paracetamol'], $this->service->trichXuatHoatChat('Paracetamol 500mg'));
    }

    public function test_san_pham_hon_hop_tra_ve_moi_hoat_chat(): void
    {
        $found = $this->service->trichXuatHoatChat('Amoxicillin 500mg + Acid clavulanic 125mg');

        $this->assertContains('amoxicillin', $found);
        $this->assertContains('acid clavulanic', $found);
    }

    public function test_ten_hop_chat_duoc_giu_nguyen_khi_tach_token(): void
    {
        $this->assertSame(['acid acetylsalicylic'], $this->service->trichXuatHoatChat('Acid acetylsalicylic 100mg'));
    }

    public function test_tu_muoi_va_don_vi_khong_bi_tinh_la_hoat_chat(): void
    {
        $found = $this->service->trichXuatHoatChat('Amlodipine besylate 5mg');

        $this->assertContains('amlodipine', $found);
        $this->assertNotContains('besylate', $found);
        $this->assertNotContains('mg', $found);
    }

    public function test_chuoi_hoat_chat_rong_tra_ve_rong(): void
    {
        $this->assertSame([], $this->service->trichXuatHoatChat(''));
    }

    // --- Pair interactions ---

    public function test_hai_nsaid_la_tuong_tac_muc_nang(): void
    {
        $found = $this->service->kiemTraCapHoatChat('Acid acetylsalicylic 100mg', 'Ibuprofen 400mg');

        $this->assertCount(1, $found);
        $this->assertSame('major', $found->first()->severity);
    }

    public function test_tra_cuu_tuong_tac_khong_phu_thuoc_thu_tu(): void
    {
        $forward = $this->service->kiemTraCapHoatChat('Ibuprofen 400mg', 'Diclofenac sodium 50mg');
        $reverse = $this->service->kiemTraCapHoatChat('Diclofenac sodium 50mg', 'Ibuprofen 400mg');

        $this->assertCount(1, $forward);
        $this->assertSame($forward->first()->ma_tt, $reverse->first()->ma_tt);
    }

    public function test_san_pham_khong_lien_quan_khong_bao_tuong_tac(): void
    {
        $this->assertCount(0, $this->service->kiemTraCapHoatChat('Paracetamol 500mg', 'Vitamin E 400IU'));
    }

    public function test_cung_mot_hoat_chat_khong_bi_bao_la_tuong_tac(): void
    {
        $this->assertCount(0, $this->service->kiemTraCapHoatChat('Paracetamol 500mg', 'Paracetamol 650mg'));
    }

    // --- Contraindications driven by the health profile ---

    public function test_di_ung_khai_bao_chan_san_pham_tuong_ung(): void
    {
        $this->profile(['allergies' => ['Paracetamol']]);
        $sanPham = $this->product('Panadol', 'Paracetamol 500mg');

        $result = $this->service->kiemTraSanPham($sanPham, $this->consentedProfile(), $this->nguoiDung);

        $this->assertTrue($result['blocking']);
        $this->assertSame('allergy', $result['warnings'][0]['type']);
    }

    public function test_mang_thai_chan_nsaid(): void
    {
        $this->profile(['is_pregnant' => true]);
        $sanPham = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->kiemTraSanPham($sanPham, $this->consentedProfile(), $this->nguoiDung);

        $this->assertTrue($result['blocking']);
        $this->assertTrue(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w['title'], 'mang thai'))
        );
    }

    public function test_benh_man_tinh_chan_thuoc_chong_chi_dinh(): void
    {
        $this->profile(['chronic_conditions' => ['loét dạ dày']]);
        $sanPham = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->kiemTraSanPham($sanPham, $this->consentedProfile(), $this->nguoiDung);

        $this->assertTrue(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w['title'], 'loét dạ dày'))
        );
    }

    public function test_tuong_tac_voi_thuoc_dang_dung_duoc_bao_cao(): void
    {
        $this->profile(['current_medications' => ['Clopidogrel 75mg']]);
        $sanPham = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->kiemTraSanPham($sanPham, $this->consentedProfile(), $this->nguoiDung);

        $this->assertTrue(
            collect($result['warnings'])->contains(fn ($w) => $w['type'] === 'current_medication')
        );
    }

    public function test_ho_so_khong_dong_y_khong_co_canh_bao_ca_nhan_hoa(): void
    {
        $this->profile(['allergies' => ['Paracetamol'], 'consented_at' => null]);
        $sanPham = $this->product('Panadol', 'Paracetamol 500mg');

        // consentedProfile() returns null, mirroring what the controllers pass in.
        $result = $this->service->kiemTraSanPham($sanPham, $this->consentedProfile(), $this->nguoiDung);

        $this->assertFalse($result['blocking']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_gioi_han_tuoi_kich_hoat_cho_tre_em(): void
    {
        $child = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(10)->toDateString(),
        ]);
        $sanPham = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');

        $result = $this->service->kiemTraSanPham($sanPham, null, $child);

        $this->assertTrue($result['blocking']);
        $this->assertTrue(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w['title'], 'dưới 16 tuổi'))
        );
    }

    public function test_gioi_han_tuoi_khong_kich_hoat_voi_nguoi_lon(): void
    {
        $sanPham = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');

        $result = $this->service->kiemTraSanPham($sanPham, null, $this->nguoiDung);

        $this->assertFalse(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w['title'], 'dưới 16 tuổi'))
        );
    }

    public function test_tuoi_khong_ro_khong_luot_qua_gioi_han_tuoi(): void
    {
        $noBirthDate = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => null,
        ]);
        $sanPham = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');

        $result = $this->service->kiemTraSanPham($sanPham, null, $noBirthDate);

        // No age known means the age rule cannot be evaluated, so it must not claim a match.
        $this->assertFalse(
            collect($result['warnings'])->contains(fn ($w) => str_contains($w['title'], 'dưới 16 tuổi'))
        );
    }

    public function test_chong_chi_dinh_khong_hoat_dong_bi_bo_qua(): void
    {
        ChungChiDinhThuoc::query()->update(['is_active' => false]);
        $this->profile(['is_pregnant' => true]);
        $sanPham = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->kiemTraSanPham($sanPham, $this->consentedProfile(), $this->nguoiDung);

        $this->assertFalse(
            collect($result['warnings'])->contains(fn ($w) => $w['type'] === 'contraindication')
        );
    }

    // --- Cart-level scanning ---

    public function test_quet_gio_hang_tim_ra_tuong_tac_giua_hai_san_pham(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->kiemTraGioHang([
            ['ma_sp' => $aspirin->ma_sp, 'quantity' => 1],
            ['ma_sp' => $ibuprofen->ma_sp, 'quantity' => 1],
        ], $this->nguoiDung->ma_nd);

        $this->assertTrue($result['blocking']);
        $this->assertTrue(collect($result['warnings'])->contains(fn ($w) => $w['type'] === 'interaction'));
    }

    public function test_quet_gio_hang_kiem_tra_moi_cap_khong_chi_hang_ke(): void
    {
        $vitamin = $this->product('Vitamin E', 'Vitamin E 400IU');
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->kiemTraGioHang([
            ['ma_sp' => $aspirin->ma_sp, 'quantity' => 1],
            ['ma_sp' => $vitamin->ma_sp, 'quantity' => 1],
            ['ma_sp' => $ibuprofen->ma_sp, 'quantity' => 1],
        ], $this->nguoiDung->ma_nd);

        $this->assertTrue(collect($result['warnings'])->contains(fn ($w) => $w['type'] === 'interaction'));
    }

    public function test_gio_hang_rong_khong_co_canh_bao(): void
    {
        $result = $this->service->kiemTraGioHang([], $this->nguoiDung->ma_nd);

        $this->assertFalse($result['blocking']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_mot_san_pham_an_toan_khong_co_canh_bao(): void
    {
        $vitamin = $this->product('Vitamin E', 'Vitamin E 400IU');

        $result = $this->service->kiemTraGioHang(
            [['ma_sp' => $vitamin->ma_sp, 'quantity' => 1]],
            $this->nguoiDung->ma_nd
        );

        $this->assertFalse($result['blocking']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_kiem_tra_them_vao_so_sanh_voi_gio_hang_hien_tai(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');
        $ibuprofen = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $result = $this->service->kiemTraThemVaoGio(
            $ibuprofen,
            [['ma_sp' => $aspirin->ma_sp, 'quantity' => 1]],
            $this->nguoiDung->ma_nd
        );

        $this->assertTrue($result['blocking']);
    }

    public function test_them_chinh_san_pham_do_khong_phai_tuong_tac_voi_no(): void
    {
        $aspirin = $this->product('Aspirin', 'Acid acetylsalicylic 100mg');

        $result = $this->service->kiemTraThemVaoGio(
            $aspirin,
            [['ma_sp' => $aspirin->ma_sp, 'quantity' => 1]],
            $this->nguoiDung->ma_nd
        );

        $this->assertFalse($result['blocking']);
    }

    public function test_canh_bao_sap_xep_nang_nhat_len_dau(): void
    {
        $this->profile([
            'allergies' => ['Paracetamol'],
            'chronic_conditions' => ['hen suyễn'],
        ]);
        $paracetamol = $this->product('Panadol', 'Paracetamol 500mg');

        $result = $this->service->kiemTraGioHang(
            [['ma_sp' => $paracetamol->ma_sp, 'quantity' => 1]],
            $this->nguoiDung->ma_nd
        );

        $this->assertSame('major', $result['warnings'][0]['severity']);
    }

    public function test_bo_qua_canh_bao_duoc_ghi_vao_nhat_ky_kiem_toan(): void
    {
        $sanPham = $this->product('Ibuprofen', 'Ibuprofen 400mg');

        $this->service->ghiNhatKyBoQua($this->nguoiDung->ma_nd, $sanPham, [
            ['type' => 'interaction', 'severity' => 'major'],
        ]);

        $log = \App\Models\NhatKyKiemToan::where('action', 'drug_warning_overridden')->latest('ma_nk')->first();

        $this->assertNotNull($log);
        $this->assertSame($sanPham->ma_sp, $log->MaDoiTuong);
    }

    // --- Helpers ---

    protected function profile(array $attributes = []): HoSoSucKhoe
    {
        return HoSoSucKhoe::updateOrCreate(
            ['ma_nd' => $this->nguoiDung->ma_nd],
            array_merge(['consented_at' => now()], $attributes)
        );
    }

    protected function consentedProfile(): ?HoSoSucKhoe
    {
        return HoSoSucKhoe::where('ma_nd', $this->nguoiDung->ma_nd)->consented()->first();
    }

    protected function product(string $name, string $ingredients): SanPham
    {
        return SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => $name,
            'thanh_phan' => $ingredients,
            'cach_dung' => 'Theo hướng dẫn',
            'gia' => 5000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 50,
            'muc_canh_bao' => 5,
            'so_lo' => 'B-' . uniqid(),
        ]);
    }
}
