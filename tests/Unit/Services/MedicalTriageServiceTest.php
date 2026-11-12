<?php

namespace Tests\Unit\Services;

use App\Models\DanhMuc;
use App\Models\TrieuChungYTe;
use App\Models\QuyTacPhanTangYTe;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use App\Services\Triage\MedicalTriageService;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicalTriageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MedicalTriageService $service;
    protected NguoiDung $nguoiDung;
    protected DanhMuc $danhMuc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->danhMuc = DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->service = app(MedicalTriageService::class);

        // 34 years old: old enough that no age guard fires.
        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(34)->toDateString(),
        ]);
    }

    protected function sessionWith(array $answers): PhienSangLoc
    {
        $phien = $this->service->startOrResume($this->nguoiDung->ma_nd);

        foreach ($answers as $step => $answer) {
            $phien = $this->service->recordAnswer($phien->fresh(), $step, $answer);
        }

        return $phien->fresh();
    }

    public function test_detects_symptom_from_accented_text(): void
    {
        $matched = $this->service->detectSymptoms('tôi bị đau đầu từ sáng');

        $this->assertTrue($matched->contains('code', 'headache'));
    }

    public function test_detects_symptom_from_unaccented_text(): void
    {
        $matched = $this->service->detectSymptoms('toi bi tieu chay tu hom qua');

        $this->assertTrue($matched->contains('code', 'diarrhea'));
    }

    public function test_alias_matching_respects_word_boundaries(): void
    {
        // "ho" is a symptom alias, but "hong" and "hop" must not trigger it.
        $matched = $this->service->detectSymptoms('cho toi hoi ve hop thuoc');

        $this->assertFalse($matched->contains('code', 'cough'));
    }

    public function test_emergency_red_flag_short_circuits_before_any_question(): void
    {
        $phien = $this->sessionWith([1 => 'đau ngực dữ dội, khó thở']);

        $this->assertTrue($this->service->hasEmergencyRedFlag($phien));
        $this->assertNull(
            $this->service->nextQuestion($phien),
            'Emergency cases must not be asked four more questions.'
        );
    }

    public function test_emergency_outcome_returns_db_guidance_and_no_products(): void
    {
        $phien = $this->sessionWith([1 => 'đau ngực dữ dội']);

        $result = $this->service->evaluate($phien);

        $this->assertSame('emergency', $result['outcome']);
        $this->assertSame([], $result['suggested_products']);
        $this->assertSame([], $result['possible_condition_groups'], 'Referral outcomes must not guess a condition.');
        $this->assertStringContainsString('115', $result['advice_given']);
    }

    public function test_child_under_two_is_always_referred(): void
    {
        $phien = $this->sessionWith([
            1 => 'bé 1 tuổi bị sốt',
            2 => '2-3 ngày',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->evaluate($phien);

        $this->assertContains($result['outcome'], ['refer_doctor', 'emergency']);
        $this->assertSame([], $result['suggested_products']);
    }

    public function test_prolonged_symptom_is_referred_rather_than_self_care(): void
    {
        $phien = $this->sessionWith([
            1 => 'sốt',
            2 => '5 ngày',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->evaluate($phien);

        $this->assertNotSame('self_care', $result['outcome']);
    }

    public function test_mild_adult_case_reaches_self_care_with_products_and_safety_net(): void
    {
        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $this->createStockedProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu, hạ sốt');

        $result = $this->service->evaluate($phien);

        $this->assertSame('self_care', $result['outcome']);
        $this->assertNotEmpty($result['advice_given']);
        $this->assertNotEmpty($result['safety_net'], 'Self-care must always state when to seek help.');
        $this->assertNotEmpty($result['possible_condition_groups']);
        $this->assertNotEmpty($result['suggested_products']);
    }

    public function test_condition_group_confidence_comes_from_the_rule_not_the_model(): void
    {
        $phien = $this->sessionWith([
            1 => 'táo bón',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->evaluate($phien);
        $quyTac = QuyTacPhanTangYTe::find($result['rule_id']);

        $this->assertSame('self_care', $result['outcome']);
        $this->assertSame(
            (float) $quyTac->confidence,
            (float) $result['possible_condition_groups'][0]['confidence']
        );
    }

    public function test_higher_priority_rule_wins_over_the_generic_one(): void
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

        $result = $this->service->evaluate($phien);

        $this->assertSame('refer_doctor', $result['outcome']);
        $this->assertSame('Quy tắc ưu tiên cao nhất.', $result['advice_given']);
    }

    public function test_inactive_rules_are_ignored(): void
    {
        QuyTacPhanTangYTe::query()->update(['is_active' => false]);

        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->evaluate($phien);

        $this->assertSame('pharmacist_review', $result['outcome']);
    }

    public function test_unrecognised_symptom_falls_back_to_pharmacist_not_a_guess(): void
    {
        $phien = $this->sessionWith([1 => 'mot dieu gi rat la khong ro rang']);

        $result = $this->service->evaluate($phien);

        $this->assertSame('pharmacist_review', $result['outcome']);
        $this->assertSame([], $result['suggested_products']);
    }

    public function test_caution_flag_downgrades_self_care_to_pharmacist_review(): void
    {
        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'đã dùng thuốc nhưng không đỡ',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $result = $this->service->evaluate($phien);

        $this->assertSame('pharmacist_review', $result['outcome']);
        $this->assertSame([], $result['suggested_products']);
    }

    public function test_skipping_duration_prevents_a_numeric_rule_from_matching(): void
    {
        $phien = $this->service->startOrResume($this->nguoiDung->ma_nd);
        $phien = $this->service->recordAnswer($phien, 1, 'sốt');
        $phien = $this->service->recordAnswer($phien->fresh(), 2, '', true);
        $phien = $this->service->recordAnswer($phien->fresh(), 3, 'chưa dùng gì');
        $phien = $this->service->recordAnswer($phien->fresh(), 4, 'không có');
        $phien = $this->service->recordAnswer($phien->fresh(), 5, 'không có thêm');

        $result = $this->service->evaluate($phien->fresh());
        $quyTac = QuyTacPhanTangYTe::find($result['rule_id']);

        // The "3+ days" rule cannot fire on an unknown duration; the generic rule does.
        $this->assertNotNull($quyTac);
        $this->assertNull($quyTac->conditions);
    }

    public function test_duration_range_resolves_to_the_upper_bound(): void
    {
        $phien = $this->sessionWith([1 => 'ho', 2 => '2-3 ngày']);

        $this->assertSame(3, $phien->collected_data['duration_days']);
    }

    public function test_week_answers_convert_to_days(): void
    {
        $phien = $this->sessionWith([1 => 'ho', 2 => 'hơn 1 tuần']);

        $this->assertGreaterThan(7, $phien->collected_data['duration_days']);
    }

    public function test_consented_profile_pregnancy_triggers_a_red_flag(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'is_pregnant' => true,
            'consented_at' => now(),
        ]);

        $hoSo = HoSoSucKhoe::where('ma_nd', $this->nguoiDung->ma_nd)->first();
        $flags = $this->service->detectRedFlags('đau đầu', $hoSo);

        $this->assertTrue($flags->contains('code', 'pregnancy_symptom'));
    }

    public function test_profile_without_consent_is_not_read(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'is_pregnant' => true,
            'consented_at' => null,
        ]);

        $hoSo = HoSoSucKhoe::where('ma_nd', $this->nguoiDung->ma_nd)->first();
        $flags = $this->service->detectRedFlags('đau đầu', $hoSo);

        $this->assertFalse($flags->contains('code', 'pregnancy_symptom'));
    }

    public function test_consented_history_lets_the_wizard_skip_the_history_step(): void
    {
        HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'chronic_conditions' => ['loét dạ dày'],
            'consented_at' => now(),
        ]);

        $hoSo = HoSoSucKhoe::where('ma_nd', $this->nguoiDung->ma_nd)->first();

        $phien = $this->service->startOrResume($this->nguoiDung->ma_nd);
        $phien = $this->service->recordAnswer($phien, 1, 'đau đầu');
        $phien = $this->service->recordAnswer($phien->fresh(), 2, 'hôm nay');
        $phien = $this->service->recordAnswer($phien->fresh(), 3, 'chưa dùng gì');

        $question = $this->service->nextQuestion($phien->fresh(), $hoSo);

        $this->assertSame(MedicalTriageService::STEP_OTHER_SYMPTOMS, $question['step']);
    }

    public function test_finalize_does_not_queue_a_pharmacist_case_automatically(): void
    {
        // The pharmacist queue is an opt-in side channel: the customer reads the
        // AI/rule answer first and asks for a human only if they want one.
        $phien = $this->sessionWith([1 => 'khong ro rang gi ca']);
        $result = $this->service->evaluate($phien);

        $phien = $this->service->finalize($phien, $result);

        $this->assertSame('pharmacist_review', $phien->outcome);
        $this->assertSame('completed', $phien->status);
        $this->assertNull($phien->follow_up_at, 'Only self-care schedules a follow-up.');
    }

    public function test_a_customer_request_moves_the_case_into_the_queue(): void
    {
        $phien = $this->service->finalize(
            $this->sessionWith([1 => 'khong ro rang gi ca']),
            $this->service->evaluate($this->sessionWith([1 => 'khong ro rang gi ca']))
        );

        $this->assertTrue($this->service->requestPharmacistReview($phien));
        $this->assertSame('pending_pharmacist', $phien->fresh()->status);
    }

    public function test_an_emergency_is_never_moved_into_the_queue(): void
    {
        // The only action for an emergency is calling 115, not waiting for a reply.
        $phien = $this->sessionWith([1 => 'tôi bị đau ngực dữ dội']);
        $phien = $this->service->finalize($phien, $this->service->evaluate($phien));

        $this->assertSame('emergency', $phien->outcome);
        $this->assertFalse($this->service->requestPharmacistReview($phien));
        $this->assertSame('completed', $phien->fresh()->status);
    }

    public function test_requesting_a_pharmacist_clears_the_follow_up(): void
    {
        $this->createStockedProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu');

        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);
        $phien = $this->service->finalize($phien, $this->service->evaluate($phien));

        $this->assertNotNull($phien->follow_up_at);
        $this->assertTrue($this->service->requestPharmacistReview($phien));

        // A queued case waits on a human, so the automated 3-day check-in would
        // collide with the pharmacist's own answer.
        $this->assertNull($phien->fresh()->follow_up_at);
    }

    public function test_finalize_schedules_follow_up_only_for_self_care(): void
    {
        $this->createStockedProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu');

        $phien = $this->sessionWith([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $phien = $this->service->finalize($phien, $this->service->evaluate($phien));

        $this->assertSame('completed', $phien->status);
        $this->assertNotNull($phien->follow_up_at);
    }

    public function test_audit_log_stores_symptom_codes_not_the_users_words(): void
    {
        $phien = $this->sessionWith([1 => 'tôi bị đau đầu kinh khủng lắm']);
        $this->service->finalize($phien, $this->service->evaluate($phien));

        $log = \App\Models\NhatKyKiemToan::where('action', 'triage_completed')->latest('ma_nk')->firstOrFail();
        $payload = json_encode($log->new_values, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('headache', $payload);
        $this->assertStringNotContainsString('kinh khủng', $payload);
    }

    public function test_resume_returns_the_same_open_session(): void
    {
        $first = $this->service->startOrResume($this->nguoiDung->ma_nd);
        $second = $this->service->startOrResume($this->nguoiDung->ma_nd);

        $this->assertSame($first->ma_sl, $second->ma_sl);
    }

    public function test_expired_products_are_never_suggested(): void
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

        $result = $this->service->evaluate($phien);
        $ids = collect($result['suggested_products'])->pluck('id');

        $this->assertTrue($ids->contains($fresh->ma_sp));
        $this->assertFalse($ids->contains($expired->ma_sp));
    }

    public function test_out_of_stock_products_are_never_suggested(): void
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

        $ids = collect($this->service->evaluate($phien)['suggested_products'])->pluck('id');

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
    public function test_the_derivable_red_flag_set_matches_the_seeded_data(): void
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
    public function test_clarify_question_text_never_reaches_the_red_flag_matcher(): void
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

        $flags = $this->service->detectRedFlags($collected->invoke($this->service, $phien));

        $this->assertSame([], $flags->pluck('code')->all());
        $this->assertFalse($this->service->hasEmergencyRedFlag($phien));
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
