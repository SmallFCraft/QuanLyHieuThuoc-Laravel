<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The triage wizard is a plain form flow — every step is a real POST that
 * redirects. These tests drive it the same way a browser with JavaScript disabled
 * would, which is the contract the feature promises.
 */
class QuyTrinhSangLocSucKhoeTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $nguoiDung;
    protected DanhMuc $danhMuc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(34)->toDateString(),
        ]);
    }

    // --- Access control ---

    public function test_khach_chua_dang_nhap_duoc_chuyen_den_trang_dang_nhap(): void
    {
        $this->get(route('sang-loc.kiem-tra'))->assertRedirect(route('login'));
    }

    public function test_khach_chua_dang_nhap_van_thay_diem_vao_tren_thanh_dieu_huong(): void
    {
        // Hiding the link would mean guests never learn the feature exists.
        $this->get(route('trang-chu'))
            ->assertOk()
            ->assertSee(route('sang-loc.kiem-tra'), false);
    }

    public function test_nguoi_dung_da_dang_nhap_thay_cau_hoi_dau_tien(): void
    {
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee('Bước 1/5')
            ->assertSee('triage-question', false)
            ->assertSee('role="progressbar"', false);
    }

    /**
     * The stepper names all five questions up front, so a visitor reads the shape of the
     * intake instead of counting steps. Node count is pinned at the same table the
     * service walks (`questionFor`), because a stepper that silently drops a node reads
     * as a shorter intake than the one the visitor is actually being asked through.
     */
    public function test_thanh_tien_trinh_dat_ten_cho_moi_buoc_tiep_nhan(): void
    {
        $html = $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->getContent();

        $stepper = substr($html, (int) strpos($html, 'class="health-stepper'));
        $this->assertNotFalse(strpos($html, 'class="health-stepper'));

        foreach (range(1, 5) as $step) {
            $this->assertStringContainsString(__("messages.health.step_{$step}"), $stepper);
        }

        // Five dots for five steps. On `health-stepper__dot` rather than the class name
        // alone: the stylesheet ships into <head> first and would answer for the markup.
        $this->assertSame(5, substr_count($stepper, 'health-stepper__dot'));
    }

    /**
     * The first question is answered on step 1 and nothing yet on step 2, so exactly one
     * node is current. Pinned because "current" is carried by a CSS class, not by
     * `aria-current`: nothing else would notice the stepper drifting to step 1 on every
     * question, which is the same thing on every page the visitor ever sees.
     */
    public function test_thanh_tien_trinh_chi_co_mot_buoc_hien_tai_va_dung_la_buoc_dang_hoi(): void
    {
        $html = $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->getContent();

        $stepper = substr($html, (int) strpos($html, 'class="health-stepper'));
        $this->assertNotFalse(strpos($html, 'class="health-stepper'));

        $this->assertSame(1, substr_count($stepper, 'health-stepper__node is-current'));
        $this->assertSame(0, substr_count($stepper, 'health-stepper__node is-done'));
    }

    /**
     * Quick-pick answers carry a clinical glyph, and the glyph comes from a table keyed
     * on the answer text. A renamed option loses its icon rather than wearing the wrong
     * one, so the failure mode worth catching is a hit with no icon — which would leave
     * a bare word with no visual anchor on the one control most visitors reach for.
     */
    public function test_lua_chon_nhanh_co_bieu_tuong_lam_sang(): void
    {
        $html = $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->getContent();

        $options = substr($html, (int) strpos($html, 'class="health-options'));
        $this->assertNotFalse(strpos($html, 'class="health-options'));

        // Six options on step 1, six icons: the map covers the step it ships with.
        $this->assertSame(6, substr_count($options, '<button type="submit" name="option_answer"'));
        $this->assertSame(6, substr_count($options, 'health-option__icon'));

        // The icon is decorative: the label beside it is the answer, and a screen reader
        // reading "Đau đầu, bi-emoji-dizzy" is worse than reading the words alone.
        $this->assertSame(6, substr_count($options, 'health-option__icon" aria-hidden="true"'));
    }

    public function test_thong_bao_pham_vi_luon_hien_day_du_va_khong_co_nut_dong(): void
    {
        $html = $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->getContent();

        // Anchored on `class="health-disclaimer` rather than the bare class name: the CSS
        // rule of the same name goes into <head> via @stack('styles') tens of thousands of
        // characters earlier, so the looser needle matched the stylesheet, not the DOM.
        $start = strpos($html, 'class="health-disclaimer');
        $this->assertNotFalse($start);

        // Bounded by the notice's own closing tag, not a character count: the modal's
        // markup begins ~1200 chars in, so a fixed window would start swallowing its
        // <button> the moment this copy got any shorter.
        $end = strpos($html, '</div>', strpos($html, '</p>', $start));
        $block = substr($html, $start, $end - $start);

        $this->assertStringContainsString('role="note"', $block);
        // The full body, not just the title: the once-a-day popup must never become the
        // only place the scope text lives.
        $this->assertStringContainsString(__('messages.health.disclaimer_body'), $block);

        $this->assertStringNotContainsStringIgnoringCase('btn-close', $block);
        $this->assertStringNotContainsStringIgnoringCase('data-bs-dismiss', $block);
        $this->assertStringNotContainsStringIgnoringCase('<button', $block);
    }

    // --- Wizard progression ---

    public function test_tra_loi_mot_buoc_chuyen_quy_trinh_sang_buoc_tiep_theo(): void
    {
        $phien = $this->startSession();

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => 1,
                'answer' => 'đau đầu',
            ])
            ->assertRedirect(route('sang-loc.kiem-tra'));

        $this->assertSame(2, $phien->fresh()->current_step);
    }

    public function test_cau_tra_loi_rong_o_buoc_dau_bat_buoc_bi_tu_choi(): void
    {
        $phien = $this->startSession();

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => 1,
                'answer' => '',
            ])
            ->assertRedirect(route('sang-loc.kiem-tra'))
            ->assertSessionHasErrors('answer');

        $this->assertSame(1, $phien->fresh()->current_step, 'A rejected answer must not advance the step.');
    }

    public function test_quay_lai_buoc_truoc_va_giu_nguyen_cau_tra_loi(): void
    {
        $phien = $this->startSession();
        $this->answer($phien, 1, 'tiêu chảy');

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.quay-lai'), ['triage_session_id' => $phien->ma_sl])
            ->assertRedirect(route('sang-loc.kiem-tra'));

        $phien = $phien->fresh();
        $this->assertSame(1, $phien->current_step);

        // The stored answer is pre-filled back into the input.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertSee('value="tiêu chảy"', false);
    }

    public function test_bo_qua_mot_buoc_duoc_ghi_nhan(): void
    {
        $phien = $this->startSession();
        $this->answer($phien, 1, 'ho');

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.tra-loi'), [
            'triage_session_id' => $phien->fresh()->ma_sl,
            'step' => 2,
            'answer' => '',
            'skipped' => 1,
        ]);

        $this->assertContains('duration_days', $phien->fresh()->collected_data['skipped']);
    }

    public function test_hoan_tat_moi_buoc_chuyen_den_url_ket_qua(): void
    {
        $this->stockProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu, hạ sốt');

        $phien = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $phien = $phien->fresh();

        $this->assertSame('completed', $phien->status);
        $this->assertSame('self_care', $phien->outcome);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.outcome_self_care'));
    }

    /**
     * The flagged card carries the drug-safety warning, so its amber level has to
     * outlive both the hover rule and the print block. Two duplicate
     * `.health-product--flagged` rules in `@media print` used to cancel the severity
     * border: the later `border-width`/`border-color` pair outranked it and printed a
     * plain grey box. The level was a left rail then and is a full border now, but
     * the guarantee is the same one: hover and print must not drain the colour.
     */
    public function test_san_pham_bi_canh_bao_giu_duong_vien_khi_re_chuot_va_khi_in(): void
    {
        $this->stockProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu, hạ sốt');

        $phien = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        $content = $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->getContent();

        // Hover outranks the base rule (two classes over one) and would otherwise
        // repaint the border with .health-product's grey.
        $this->assertMatchesRegularExpression(
            '/\.health-product--flagged:hover\s*\{[^}]*border-color:\s*var\(--health-refer\)/s',
            $content
        );

        // Exactly one `!important` level declaration in the print block: the old block
        // had a second `.health-product--flagged` rule whose border pair won, so the
        // flagged card printed as a plain grey box.
        $this->assertSame(1, substr_count($content, 'border: 2px solid var(--health-refer) !important'));
        $this->assertStringNotContainsString('border-color: #374151', $content);
    }

    public function test_tai_lai_giua_quy_trinh_giu_nguyen_buoc_hien_tai(): void
    {
        $phien = $this->startSession();
        $this->answer($phien, 1, 'ho');
        $this->answer($phien->fresh(), 2, 'hôm nay');

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee('Bước 3/5');
    }

    public function test_khoi_dong_lai_huy_phien_dang_mo(): void
    {
        $phien = $this->startSession();
        $this->answer($phien, 1, 'ho');

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.bat-dau-lai'))
            ->assertRedirect(route('sang-loc.kiem-tra'));

        $this->assertNull(PhienSangLoc::find($phien->ma_sl));
    }

    public function test_tra_loi_phien_cu_bao_phien_da_het_han(): void
    {
        $phien = $this->startSession();
        $phien->update(['status' => 'completed']);

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => 1,
                'answer' => 'đau đầu',
            ])
            ->assertRedirect(route('sang-loc.kiem-tra'))
            ->assertSessionHas('error');
    }

    // --- Outcome rendering ---

    public function test_cap_cuu_dung_quy_trinh_som_va_chi_de_xuat_goi_115(): void
    {
        $phien = $this->startSession();

        // Emergency ends questioning immediately: the very first answer finalises
        // instead of asking step 2.
        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => 1,
                'answer' => 'đau ngực dữ dội, khó thở',
            ])
            ->assertRedirect(route('sang-loc.ket-qua', $phien->ma_sl));

        $phien = $phien->fresh();
        $this->assertSame('emergency', $phien->outcome);
        $this->assertSame([], $phien->suggested_products);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee('tel:115', false)
            ->assertSee(__('messages.health.outcome_emergency'))
            ->assertSee(__('messages.health.no_products'));
    }

    public function test_ket_qua_di_kham_khong_hien_khoi_san_pham(): void
    {
        $this->stockProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Hạ sốt');

        $phien = $this->runWizard([
            1 => 'bé 1 tuổi bị sốt cao',
            2 => '2-3 ngày',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        $this->assertContains($phien->outcome, ['refer_doctor', 'emergency']);
        $this->assertSame([], $phien->suggested_products);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.no_products'))
            ->assertDontSee(__('messages.health.products_title'));
    }

    /**
     * The safety-net block and the possible-condition guesses were removed at the
     * customer's request, and the product list moved with them. The ordering rule
     * they encoded still holds where it matters: the conclusion and the path to it
     * come before anything purchasable.
     */
    public function test_ket_luan_xuat_hien_truoc_danh_sach_san_pham(): void
    {
        $this->stockProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu');

        $phien = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        $html = $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->getContent();

        $journeyPos = strpos($html, __('messages.health.journey_title'));
        $productsPos = strpos($html, __('messages.health.products_title'));

        $this->assertNotFalse($journeyPos);
        $this->assertNotFalse($productsPos);
        $this->assertLessThan(
            $productsPos,
            $journeyPos,
            'How the conclusion was reached must be read before anything purchasable.'
        );
    }

    public function test_truong_hop_chua_chac_chan_de_xuat_duoc_si_thay_vi_tu_vao_hang_doi(): void
    {
        $phien = $this->runWizard([
            1 => 'dieu gi rat khong ro rang',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        // The AI/rule answer is the result; the queue is opt-in.
        $this->assertSame('pharmacist_review', $phien->outcome);
        $this->assertSame('completed', $phien->status);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.pharmacist_request_btn'))
            ->assertDontSee(__('messages.health.pharmacist_waiting'));
    }

    public function test_yeu_cau_duoc_si_hien_thoi_gian_cho_du_kien(): void
    {
        $phien = $this->runWizard([
            1 => 'dieu gi rat khong ro rang',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.xin-duoc-si', $phien->ma_sl))
            ->assertRedirect(route('sang-loc.ket-qua', $phien->ma_sl));

        $this->assertSame('pending_pharmacist', $phien->fresh()->status);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.pharmacist_waiting'))
            ->assertDontSee(__('messages.health.pharmacist_request_btn'));
    }

    public function test_ket_qua_cap_cuu_khong_bao_gio_de_xuat_kenh_duoc_si(): void
    {
        $phien = $this->runWizard([1 => 'tôi bị đau ngực dữ dội'])->fresh();

        $this->assertSame('emergency', $phien->outcome);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertDontSee(__('messages.health.pharmacist_request_btn'));
    }

    public function test_nguoi_dung_khong_the_yeu_cau_duoc_si_cho_phien_cua_nguoi_khac(): void
    {
        $phien = $this->runWizard([
            1 => 'dieu gi rat khong ro rang',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        $this->actingAs($other)
            ->post(route('sang-loc.xin-duoc-si', $phien->ma_sl))
            ->assertNotFound();

        $this->assertSame('completed', $phien->fresh()->status);
    }

    // --- Result page ownership ---

    public function test_nguoi_dung_khong_the_mo_ket_qua_cua_nguoi_khac(): void
    {
        $phien = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        $this->actingAs($other)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertNotFound();
    }

    public function test_phien_chua_hoan_tat_khong_co_trang_ket_qua(): void
    {
        $phien = $this->startSession();
        $this->answer($phien, 1, 'ho');

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertNotFound();
    }

    // --- History ---

    public function test_lich_su_trong_truoc_lan_sang_loc_dau_tien(): void
    {
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.lich-su'))
            ->assertOk()
            ->assertSee(__('messages.health.history_empty'));
    }

    public function test_lich_su_chi_liet_ke_cac_lan_sang_loc_da_hoan_tat(): void
    {
        $finished = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $inProgress = $this->startSession();
        $this->answer($inProgress, 1, 'ho');

        $response = $this->actingAs($this->nguoiDung)->get(route('sang-loc.lich-su'))->assertOk();

        $response->assertSee(route('sang-loc.ket-qua', $finished->ma_sl), false);
        $response->assertDontSee(route('sang-loc.ket-qua', $inProgress->ma_sl), false);
    }

    public function test_lich_su_chi_hien_cac_lan_sang_loc_cua_chinh_nguoi_dung(): void
    {
        $mine = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        $this->actingAs($other)
            ->get(route('sang-loc.lich-su'))
            ->assertOk()
            ->assertDontSee(route('sang-loc.ket-qua', $mine->ma_sl), false);
    }

    // --- Health profile integration ---

    public function test_ho_so_da_dong_y_cho_phep_bo_qua_cau_hoi_tien_su(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'chronic_conditions' => ['loét dạ dày'],
            'consented_at' => now(),
        ]);

        $phien = $this->startSession();
        $this->answer($phien, 1, 'đau đầu');
        $this->answer($phien->fresh(), 2, 'hôm nay');
        $this->answer($phien->fresh(), 3, 'chưa dùng gì');

        // Step 4 is answered by the profile, so step 5 comes next.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee('Bước 5/5');
    }

    public function test_nguoi_dung_chua_co_ho_so_thay_loi_nhac_tao_ho_so(): void
    {
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee(__('messages.health.profile_cta'));
    }

    // --- Rate limiting ---

    public function test_diem_cuoi_tra_loi_bi_gioi_han_tan_suat(): void
    {
        $phien = $this->startSession();

        // The route allows 20 requests per minute.
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($this->nguoiDung)->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => 1,
                'answer' => 'đau đầu',
            ]);
        }

        $this->actingAs($this->nguoiDung)
            ->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => 1,
                'answer' => 'đau đầu',
            ])
            ->assertStatus(429);
    }

    // --- Helpers ---

    protected function startSession(): PhienSangLoc
    {
        $this->actingAs($this->nguoiDung)->get(route('sang-loc.kiem-tra'));

        return PhienSangLoc::where('ma_nd', $this->nguoiDung->ma_nd)->inProgress()->latest('ma_sl')->firstOrFail();
    }

    protected function answer(PhienSangLoc $phien, int $step, string $answer): void
    {
        $this->actingAs($this->nguoiDung)->post(route('sang-loc.tra-loi'), [
            'triage_session_id' => $phien->ma_sl,
            'step' => $step,
            'answer' => $answer,
        ]);
    }

    /**
     * Walk the wizard step by step and return the session that was worked on.
     *
     * Deliberately does NOT re-GET /health-check afterwards: that would start a
     * fresh session and mask which one the assertions are about. The final answer
     * POST is what finalises, so nothing else is needed.
     */
    protected function runWizard(array $answers): PhienSangLoc
    {
        $phien = $this->startSession();
        $id = $phien->ma_sl;

        foreach ($answers as $step => $answer) {
            $current = PhienSangLoc::find($id);

            if ($current === null || $current->status !== 'in_progress') {
                break;
            }

            $this->answer($current, $step, $answer);
        }

        return PhienSangLoc::findOrFail($id);
    }

    protected function stockProduct(string $name, string $ingredients, string $usage): SanPham
    {
        return SanPham::create(['ma_dm' => $this->danhMuc->ma_dm,
            'ten' => $name,
            'thanh_phan' => $ingredients,
            'cach_dung' => $usage,
            'gia' => 5000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 50,
            'muc_canh_bao' => 5,
            'so_lo' => 'B-' . uniqid(),
        ]);
    }
}
