<?php

namespace Tests\Unit\Services;

use App\Models\DanhMuc;
use App\Models\TrieuChungYTe;
use App\Models\QuyTacPhanTangYTe;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use App\Services\Triage\SangLocYTeService;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SangLocYTeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SangLocYTeService $service;
    protected NguoiDung $nguoiDung;
    protected DanhMuc $danhMuc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->service = app(SangLocYTeService::class);

        // 34 years old: old enough that no age guard fires.
        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(34)->toDateString(),
        ]);
    }

    protected function sessionWith(array $answers): PhienSangLoc
    {
        $phien = $this->service->batDauHoacTiepTuc($this->nguoiDung->ma_nd);

        foreach ($answers as $step => $answer) {
            $phien = $this->service->ghiCauTraLoi($phien->fresh(), $step, $answer);
        }

        return $phien->fresh();
    }

    public function test_nhan_dien_trieu_chung_tu_chu_co_dau(): void
    {
        $matched = $this->service->phanLoaiTrieuChung('tôi bị đau đầu từ sáng');

        $this->assertTrue($matched->contains('code', 'headache'));
    }

    public function test_nhan_dien_trieu_chung_tu_chu_khong_dau(): void
    {
        $matched = $this->service->phanLoaiTrieuChung('toi bi tieu chay tu hom qua');

        $this->assertTrue($matched->contains('code', 'diarrhea'));
    }

    public function test_khop_biet_danh_tu_trong_ranh_gi_tu(): void
    {
        // "ho" is a symptom alias, but "hong" and "hop" must not trigger it.
        $matched = $this->service->phanLoaiTrieuChung('cho toi hoi ve hop thuoc');

        $this->assertFalse($matched->contains('code', 'cough'));
    }

    public function test_co_do_cap_cuu_dung_lai_truoc_moi_cau_hoi(): void
    {
        $phien = $this->sessionWith([1 => 'đau ngực dữ dội, khó thở']);

        $this->assertTrue($this->service->coCapCuuCuu($phien));
        $this->assertNull(
            $this->service->cauHoiTiep($phien),
            'Emergency cases must not be asked four more questions.'
        );
    }

    public function test_ket_qua_cap_cuu_tra_loi_dan_bac_si_va_khong_goi_y_san_pham(): void
    {
        $phien = $this->sessionWith([1 => 'đau ngực dữ dội']);

        $result = $this->service->danhGia($phien);

        $this->assertSame('emergency', $result['outcome']);
        $this->assertSame([], $result['suggested_products']);
        $this->assertSame([], $result['possible_condition_groups'], 'Referral outcomes must not guess a condition.');
        $this->assertStringContainsString('115', $result['advice_given']);
    }

    public function test_tre_duoi_hai_tuoi_luon_duoc_chuyen_bac_si(): void
    {
        $phien = $this->sessionWith([
            1 => 'bé 1 tuổi bị sốt',
            2 => '2-3 ngày',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->danhGia($phien);

        $this->assertContains($result['outcome'], ['refer_doctor', 'emergency']);
        $this->assertSame([], $result['suggested_products']);
    }

    public function test_trieu_chung_keo_dai_duoc_chuyen_bac_si_thay_vi_tu_cham_soc(): void
    {
        $phien = $this->sessionWith([
            1 => 'sốt',
            2 => '5 ngày',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->danhGia($phien);

        $this->assertNotSame('self_care', $result['outcome']);
    }

    public function test_truong_hop_nhe_duoc_tu_cham_soc_kem_san_pham_va_luoi_an_toan(): void
    {
        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $this->createStockedProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu, hạ sốt');

        $result = $this->service->danhGia($phien);

        $this->assertSame('self_care', $result['outcome']);
        $this->assertNotEmpty($result['advice_given']);
        $this->assertNotEmpty($result['safety_net'], 'Self-care must always state when to seek help.');
        $this->assertNotEmpty($result['possible_condition_groups']);
        $this->assertNotEmpty($result['suggested_products']);
    }

    public function test_do_tin_cay_nhom_benh_lay_tu_quy_tac_chu_khong_phai_mo_hinh(): void
    {
        $phien = $this->sessionWith([
            1 => 'táo bón',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->danhGia($phien);
        $quyTac = QuyTacPhanTangYTe::find($result['rule_id']);

        $this->assertSame('self_care', $result['outcome']);
        $this->assertSame(
            (float) $quyTac->confidence,
            (float) $result['possible_condition_groups'][0]['confidence']
        );
    }

    public function test_quy_tac_uu_tien_cao_hon_thang_quy_tac_tong_quat(): void
    {
        $trieuChung = TrieuChungYTe::where('code', 'headache')->firstOrFail();

        QuyTacPhanTangYTe::create([
            'ma_tc' => $trieuChung->ma_tc,
            'conditions' => null,
            'outcome' => 'refer_doctor',
            'advice_vi' => 'Quy tắc ưu tiên cao nhất.',
            'priority' => 999,
            'is_active' => true,
        ]);

        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->danhGia($phien);

        $this->assertSame('refer_doctor', $result['outcome']);
        $this->assertSame('Quy tắc ưu tiên cao nhất.', $result['advice_given']);
    }

    public function test_quy_tac_khong_hoat_dong_bi_bo_qua(): void
    {
        QuyTacPhanTangYTe::query()->update(['is_active' => false]);

        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->danhGia($phien);

        $this->assertSame('pharmacist_review', $result['outcome']);
    }

    public function test_trieu_chung_khong_ro_tra_ve_duoc_si_thay_vi_doan(): void
    {
        $phien = $this->sessionWith([1 => 'mot dieu gi rat la khong ro rang']);

        $result = $this->service->danhGia($phien);

        $this->assertSame('pharmacist_review', $result['outcome']);
        $this->assertSame([], $result['suggested_products']);
    }

    public function test_co_bao_dong_ha_muc_tu_cham_soc_xuong_duyet_dac_si(): void
    {
        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'đã dùng thuốc nhưng không đỡ',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->danhGia($phien);

        $this->assertSame('pharmacist_review', $result['outcome']);
        $this->assertSame([], $result['suggested_products']);
    }

    public function test_bo_qua_thoi_gian_chan_quy_tac_so_khoi_khop(): void
    {
        $phien = $this->service->batDauHoacTiepTuc($this->nguoiDung->ma_nd);
        $phien = $this->service->ghiCauTraLoi($phien, 1, 'sốt');
        $phien = $this->service->ghiCauTraLoi($phien->fresh(), 2, '', true);
        $phien = $this->service->ghiCauTraLoi($phien->fresh(), 3, 'chưa dùng gì');
        $phien = $this->service->ghiCauTraLoi($phien->fresh(), 4, 'không có');
        $phien = $this->service->ghiCauTraLoi($phien->fresh(), 5, 'không có thêm');

        $result = $this->service->danhGia($phien->fresh());
        $quyTac = QuyTacPhanTangYTe::find($result['rule_id']);

        // The "3+ days" rule cannot fire on an unknown duration; the generic rule does.
        $this->assertNotNull($quyTac);
        $this->assertNull($quyTac->conditions);
    }

    public function test_khoang_thoi_gian_chot_gia_tri_tren(): void
    {
        $phien = $this->sessionWith([1 => 'ho', 2 => '2-3 ngày']);

        $this->assertSame(3, $phien->collected_data['duration_days']);
    }

    public function test_cau_tra_loi_tuan_duoc_quy_doi_ra_ngay(): void
    {
        $phien = $this->sessionWith([1 => 'ho', 2 => 'hơn 1 tuần']);

        $this->assertGreaterThan(7, $phien->collected_data['duration_days']);
    }

    public function test_ho_so_dong_y_dang_mang_thai_kich_hoat_co_do(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'is_pregnant' => true,
            'consented_at' => now(),
        ]);

        $hoSo = HoSoSucKhoe::where('ma_nd', $this->nguoiDung->ma_nd)->first();
        $flags = $this->service->phanLoaiCoDo('đau đầu', $hoSo);

        $this->assertTrue($flags->contains('code', 'pregnancy_symptom'));
    }

    public function test_ho_so_khong_dong_y_khong_duoc_doc(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'is_pregnant' => true,
            'consented_at' => null,
        ]);

        $hoSo = HoSoSucKhoe::where('ma_nd', $this->nguoiDung->ma_nd)->first();
        $flags = $this->service->phanLoaiCoDo('đau đầu', $hoSo);

        $this->assertFalse($flags->contains('code', 'pregnancy_symptom'));
    }

    public function test_ho_so_dong_y_cho_wizard_bo_qua_buoc_tien_su(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'chronic_conditions' => ['loét dạ dày'],
            'consented_at' => now(),
        ]);

        $hoSo = HoSoSucKhoe::where('ma_nd', $this->nguoiDung->ma_nd)->first();

        $phien = $this->service->batDauHoacTiepTuc($this->nguoiDung->ma_nd);
        $phien = $this->service->ghiCauTraLoi($phien, 1, 'đau đầu');
        $phien = $this->service->ghiCauTraLoi($phien->fresh(), 2, 'hôm nay');
        $phien = $this->service->ghiCauTraLoi($phien->fresh(), 3, 'chưa dùng gì');

        $question = $this->service->cauHoiTiep($phien->fresh(), $hoSo);

        $this->assertSame(SangLocYTeService::STEP_OTHER_SYMPTOMS, $question['step']);
    }

    public function test_hoan_tat_khong_tu_dong_dua_vao_hang_cho_duoc_si(): void
    {
        // The pharmacist queue is an opt-in side channel: the customer reads the
        // AI/rule answer first and asks for a human only if they want one.
        $phien = $this->sessionWith([1 => 'khong ro rang gi ca']);
        $result = $this->service->danhGia($phien);

        $phien = $this->service->hoanTat($phien, $result);

        $this->assertSame('pharmacist_review', $phien->outcome);
        $this->assertSame('completed', $phien->status);
        $this->assertNull($phien->follow_up_at, 'Only self-care schedules a follow-up.');
    }

    public function test_yeu_cau_khach_hang_dua_vao_hang_cho(): void
    {
        $phien = $this->service->hoanTat(
            $this->sessionWith([1 => 'khong ro rang gi ca']),
            $this->service->danhGia($this->sessionWith([1 => 'khong ro rang gi ca']))
        );

        $this->assertTrue($this->service->yeuCauDuyetDacSi($phien));
        $this->assertSame('pending_pharmacist', $phien->fresh()->status);
    }

    public function test_truong_hop_cap_cuu_khong_bao_gio_vao_hang_cho(): void
    {
        // The only action for an emergency is calling 115, not waiting for a reply.
        $phien = $this->sessionWith([1 => 'tôi bị đau ngực dữ dội']);
        $phien = $this->service->hoanTat($phien, $this->service->danhGia($phien));

        $this->assertSame('emergency', $phien->outcome);
        $this->assertFalse($this->service->yeuCauDuyetDacSi($phien));
        $this->assertSame('completed', $phien->fresh()->status);
    }

    public function test_yeu_cau_duoc_si_xoa_lich_nhap_lai(): void
    {
        $this->createStockedProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu');

        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);
        $phien = $this->service->hoanTat($phien, $this->service->danhGia($phien));

        $this->assertNotNull($phien->follow_up_at);
        $this->assertTrue($this->service->yeuCauDuyetDacSi($phien));

        // A queued case waits on a human, so the automated 3-day check-in would
        // collide with the pharmacist's own answer.
        $this->assertNull($phien->fresh()->follow_up_at);
    }

    public function test_hoan_tat_dat_lich_nhap_lai_chi_cho_tu_cham_soc(): void
    {
        $this->createStockedProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu');

        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $phien = $this->service->hoanTat($phien, $this->service->danhGia($phien));

        $this->assertSame('completed', $phien->status);
        $this->assertNotNull($phien->follow_up_at);
    }

    public function test_nhat_ky_luu_ma_trieu_chung_chu_khong_luu_loi_nguoi_dung(): void
    {
        $phien = $this->sessionWith([1 => 'tôi bị đau đầu kinh khủng lắm']);
        $this->service->hoanTat($phien, $this->service->danhGia($phien));

        $log = \App\Models\NhatKyKiemToan::where('action', 'triage_completed')->latest('ma_nk')->firstOrFail();
        $payload = json_encode($log->new_values, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('headache', $payload);
        $this->assertStringNotContainsString('kinh khủng', $payload);
    }

    public function test_tiep_tuc_tra_ve_cung_phien_dang_mo(): void
    {
        $first = $this->service->batDauHoacTiepTuc($this->nguoiDung->ma_nd);
        $second = $this->service->batDauHoacTiepTuc($this->nguoiDung->ma_nd);

        $this->assertSame($first->ma_sl, $second->ma_sl);
    }

    public function test_san_pham_het_han_khong_bao_gio_duoc_goi_y(): void
    {
        $fresh = $this->createStockedProduct('Paracetamol Fresh', 'Paracetamol 500mg', 'Giảm đau đầu');
        $expired = $this->createStockedProduct('Paracetamol Expired', 'Paracetamol 500mg', 'Giảm đau đầu');
        // Bypass the "expiration must be in the future" form rule; this is a DB-level state.
        $expired->forceFill(['ngay_het_han' => now()->subDay()])->save();

        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->danhGia($phien);
        $ids = collect($result['suggested_products'])->pluck('id');

        $this->assertTrue($ids->contains($fresh->ma_sp));
        $this->assertFalse($ids->contains($expired->ma_sp));
    }

    public function test_san_pham_het_hang_khong_bao_gio_duoc_goi_y(): void
    {
        $inStock = $this->createStockedProduct('Paracetamol In Stock', 'Paracetamol 500mg', 'Giảm đau đầu');
        $outOfStock = $this->createStockedProduct('Paracetamol Empty', 'Paracetamol 500mg', 'Giảm đau đầu', 0);

        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $ids = collect($this->service->danhGia($phien)['suggested_products'])->pluck('id');

        $this->assertTrue($ids->contains($inStock->ma_sp));
        $this->assertFalse($ids->contains($outOfStock->ma_sp));
    }

    /**
     * Pins the discriminator that decides which red flags a structured signal may reach.
     *
     * A flag is derivable when its own keyword list contains the linked symptom's plain
     * name — meaning text saying only that name already fires it today, so reaching it
     * from an affirmation or an AI code cannot produce an outcome the text tier could
     * not. Degree-qualified flags ("sốt cao kéo dài", "tiêu chảy nặng") describe a worse
     * state than the bare name and must stay out.
     *
     * If a seeder edit ever adds "sot" to high_fever_persistent's keywords, plain fever
     * becomes an urgent referral for everyone. This test is what makes that fail loudly.
     */
    public function test_bo_co_do_ke_ra_khop_voi_du_lieu_seed(): void
    {
        $method = new \ReflectionMethod($this->service, 'derivableRedFlags');
        $method->setAccessible(true);

        $codes = $method->invoke($this->service)->pluck('code')->sort()->values()->all();

        $this->assertSame([
            'blood_in_stool',
            'breathing_difficulty',
            'chest_pain_severe',
            'dengue_suspected',
            'severe_dehydration',
            'vomiting_blood',
        ], $codes);
    }

    /**
     * The question text must never reach the keyword matcher.
     *
     * Folding it in is the trap: fold("...đau ngực không?" . " Không") affirms every
     * keyword in it, so a patient who denied every danger sign would be told to call 115.
     */
    public function test_cau_hoi_lam_ro_khong_bao_gio_dinh_vao_bo_khop_co_do(): void
    {
        $phien = PhienSangLoc::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'in_progress',
            'collected_data' => [
                'symptoms' => 'ho nhiều',
                'raw_symptom_text' => 'ho nhiều',
                'ai_clarify_answers' => [[
                    'round' => 1,
                    'question' => 'Anh/chị có sốt, khó thở hoặc đau ngực không?',
                    'answer' => 'Không',
                ]],
            ],
        ]);

        $collected = new \ReflectionMethod($this->service, 'collectedText');
        $collected->setAccessible(true);

        $flags = $this->service->phanLoaiCoDo($collected->invoke($this->service, $phien));

        $this->assertSame([], $flags->pluck('code')->all());
        $this->assertFalse($this->service->coCapCuuCuu($phien));
    }

    protected function createStockedProduct(
        string $name,
        string $ingredients,
        string $usage,
        int $stock = 50
    ): SanPham {
        return SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => $name,
            'thanh_phan' => $ingredients,
            'cach_dung' => $usage,
            'gia' => 5000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => $stock,
            'muc_canh_bao' => 5,
            'so_lo' => 'B-' . uniqid(),
        ]);
    }
}
