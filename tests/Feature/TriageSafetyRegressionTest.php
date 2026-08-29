<?php

namespace Tests\Feature;

use App\Models\DrugContraindication;
use App\Models\MedicationReminder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\User;
use App\Models\UserHealthProfile;
use App\Services\MedicalTriageService;
use App\Services\MedicationReminderService;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Regressions from the triage/drug-safety code review. Each test names the defect
 * it pins so a future refactor cannot quietly reintroduce it.
 */
class TriageSafetyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MedicalKnowledgeSeeder::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(40)->toDateString(),
        ]);
    }

    // --- Wizard step integrity ---

    public function test_an_out_of_order_step_cannot_finalise_the_wizard(): void
    {
        $session = $this->startSession();

        $this->actingAs($this->user)
            ->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => 5,
                'answer' => 'khong co gi them',
            ])
            ->assertRedirect(route('health.check'));

        $session->refresh();

        $this->assertSame('in_progress', $session->status);
        $this->assertNull($session->outcome);
        $this->assertSame(1, $session->current_step);
        $this->assertSame([], $session->collected_data);
    }

    public function test_a_replayed_answer_does_not_rewind_the_wizard(): void
    {
        $session = $this->startSession();
        $this->answer($session->MaSL, 1, 'dau dau');
        $this->answer($session->MaSL, 2, '3 ngay');

        $this->assertSame(3, $session->fresh()->current_step);

        // Browser Back then resubmit: the stale form still carries step=1.
        $this->answer($session->MaSL, 1, 'dau dau');

        $this->assertSame(3, $session->fresh()->current_step, 'A stale form must not rewind the wizard.');
    }

    public function test_answering_a_skipped_step_clears_its_skipped_marker(): void
    {
        $session = $this->startSession();
        $this->answer($session->MaSL, 1, 'dau dau');

        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 2,
            'skipped' => 1,
        ]);

        $this->assertContains('duration_days', $session->fresh()->collected_data['skipped']);

        $this->actingAs($this->user)->post(route('health.back'), ['triage_session_id' => $session->MaSL]);
        $this->answer($session->MaSL, 2, '5 ngay');

        $data = $session->fresh()->collected_data;

        $this->assertSame(5, $data['duration_days']);
        $this->assertNotContains('duration_days', $data['skipped'], 'An answered step must not stay marked as skipped.');
    }

    public function test_a_clicked_option_is_recorded_instead_of_the_empty_text_box(): void
    {
        $session = $this->startSession();
        $this->answer($session->MaSL, 1, 'ho');

        // What the browser sends when a quick-pick button is clicked.
        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 2,
            'option_answer' => '2–3 ngày',
            'answer' => '',
        ]);

        $data = $session->fresh()->collected_data;

        $this->assertSame(3, $data['duration_days']);
        $this->assertNotContains('duration_days', $data['skipped']);
    }

    // --- Age parsing ---

    public function test_a_duration_in_months_is_not_read_as_an_infant_age(): void
    {
        $session = TriageSession::create([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'collected_data' => [
                'raw_symptom_text' => 'toi bi sot 3 thang roi',
                'symptoms' => 'toi bi sot 3 thang roi',
                'duration_days' => 90,
                'skipped' => [],
            ],
        ]);

        $result = app(MedicalTriageService::class)->evaluate($session, null);

        $this->assertStringNotContainsString('Trẻ dưới 2 tuổi', (string) $result['advice_given']);
    }

    public function test_a_stated_child_age_in_months_still_resolves_to_an_infant(): void
    {
        $session = TriageSession::create([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'collected_data' => [
                'raw_symptom_text' => 'be 8 thang bi sot',
                'symptoms' => 'be 8 thang bi sot',
                'duration_days' => 2,
                'skipped' => [],
            ],
        ]);

        $result = app(MedicalTriageService::class)->evaluate($session, null);

        $this->assertSame('refer_doctor', $result['outcome']);
        $this->assertStringContainsString('Trẻ dưới 2 tuổi', (string) $result['advice_given']);
    }

    // --- Red flag context ---

    /**
     * The wizard's own follow-up round offers a severity scale, and "dữ dội" is one of
     * the options it puts in front of the patient. Matching that answer against the
     * unscoped `severe_pain` keyword turned every patient who picked the top option
     * into a 115 call. Measured: a 20-year-old reporting period cramps.
     */
    public function test_a_severity_answer_to_our_own_scale_question_is_not_an_emergency(): void
    {
        $session = $this->clarifiedSession('toi toi ky kinh nguyet, dau bung', [
            ['round' => 1, 'question' => 'Anh/chị bao nhiêu tuổi ạ?', 'answer' => '20'],
            ['round' => 1, 'question' => 'Anh/chị đau mức độ nhẹ, vừa hay dữ dội ạ?', 'answer' => 'đau dữ dội'],
            ['round' => 1, 'question' => 'Anh/chị đau liên tục hay từng cơn ạ?', 'answer' => 'liên tục'],
        ]);

        $result = app(MedicalTriageService::class)->evaluate($session, null);

        $this->assertNotSame('emergency', $result['outcome']);
        $this->assertNotContains('severe_pain', $this->flagCodes($result));
    }

    /**
     * The other direction, which matters more: a danger sign the patient volunteers in a
     * follow-up answer must still escalate. Only an answer that echoes a scale WE offered
     * is discounted.
     */
    public function test_a_danger_sign_volunteered_in_a_follow_up_answer_still_escalates(): void
    {
        $session = $this->clarifiedSession('toi bi ho va sot nhe', [
            ['round' => 1, 'question' => 'Anh/chị còn triệu chứng nào khác không ạ?', 'answer' => 'khó thở nhiều lắm'],
        ]);

        $result = app(MedicalTriageService::class)->evaluate($session, null);

        $this->assertSame('emergency', $result['outcome']);
        $this->assertContains('breathing_difficulty', $this->flagCodes($result));
    }

    /**
     * A named danger sign asked about directly is not a severity scale — one intensity
     * word in the question must not disarm the flag.
     */
    public function test_a_confirmed_named_danger_sign_still_escalates(): void
    {
        $session = $this->clarifiedSession('toi hoi tuc nguc khi ho', [
            ['round' => 1, 'question' => 'Anh/chị có đau ngực dữ dội không ạ?', 'answer' => 'đau ngực dữ dội lắm'],
        ]);

        $result = app(MedicalTriageService::class)->evaluate($session, null);

        $this->assertSame('emergency', $result['outcome']);
        $this->assertContains('chest_pain_severe', $this->flagCodes($result));
    }

    /** `severe_pain` keeps its original job: severe pain the patient reports unprompted. */
    public function test_unexplained_severe_pain_stated_by_the_patient_still_escalates(): void
    {
        $session = $this->clarifiedSession('toi dau du doi tu sang, khong biet vi sao', []);

        $result = app(MedicalTriageService::class)->evaluate($session, null);

        $this->assertSame('emergency', $result['outcome']);
        $this->assertContains('severe_pain', $this->flagCodes($result));
    }

    // --- Menstrual cramps ---

    public function test_typical_menstrual_cramps_reach_self_care_with_a_safety_net(): void
    {
        $session = $this->clarifiedSession('toi bi dau bung kinh, dau bung duoi', []);

        $result = app(MedicalTriageService::class)->evaluate($session, null);

        $this->assertSame('self_care', $result['outcome']);
        $this->assertContains('menstrual_cramps', $result['matched_symptoms']);
        $this->assertNotEmpty($result['safety_net']);
    }

    /**
     * The generic abdominal-pain rule sits at priority 70 and would otherwise swallow
     * every period-pain session into a pharmacist queue.
     */
    public function test_period_pain_outranks_the_generic_abdominal_pain_rule(): void
    {
        $session = $this->clarifiedSession('dau bung kinh', []);

        $result = app(MedicalTriageService::class)->evaluate($session, null);

        $this->assertContains('abdominal_pain', $result['matched_symptoms']);
        $this->assertSame('self_care', $result['outcome']);
    }

    public function test_heavy_menstrual_bleeding_is_referred(): void
    {
        $session = $this->clarifiedSession('dau bung kinh va bi rong kinh', []);

        $result = app(MedicalTriageService::class)->evaluate($session, null);

        $this->assertSame('refer_doctor', $result['outcome']);
    }

    // --- Pharmacist queue scope ---

    public function test_a_pharmacist_cannot_open_a_session_that_never_entered_the_queue(): void
    {
        $pharmacist = User::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);

        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['penicillin'],
            'consented_at' => now(),
        ]);

        $session = TriageSession::create([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 2,
            'status' => 'in_progress',
            'collected_data' => ['raw_symptom_text' => 'dau dau'],
        ]);

        $this->actingAs($pharmacist)
            ->get(route('pharmacist.triage.show', $session->MaSL))
            ->assertNotFound();
    }

    // --- Follow-up ---

    public function test_the_follow_up_link_only_renders_a_confirmation(): void
    {
        $session = $this->selfCareSession();

        $this->actingAs($this->user)
            ->get($this->signedFollowUpUrl($session, false))
            ->assertOk()
            ->assertSee(__('messages.reminder.response_confirm_button'));

        $session->refresh();

        $this->assertNull($session->follow_up_responded_at, 'Opening the link must not record an answer.');
        $this->assertSame('self_care', $session->outcome);
    }

    public function test_an_unsigned_follow_up_link_is_rejected(): void
    {
        $session = $this->selfCareSession();

        $this->actingAs($this->user)
            ->get(route('health.followup.confirm', ['MaSL' => $session->MaSL, 'improved' => 0]))
            ->assertForbidden();
    }

    public function test_a_follow_up_answer_is_recorded_once(): void
    {
        $session = $this->selfCareSession();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->user)
                ->post(route('health.followup', $session->MaSL), ['improved' => 0]);
        }

        $this->assertSame(1, \App\Models\AuditLog::where('action', 'triage_follow_up_not_improved')->count());
        $this->assertNotNull($session->fresh()->follow_up_responded_at);
    }

    public function test_a_follow_up_answer_never_downgrades_an_emergency(): void
    {
        $session = TriageSession::create([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'emergency',
            'advice_given' => 'Gọi ngay 115.',
            'collected_data' => ['raw_symptom_text' => 'dau nguc du doi'],
        ]);

        $this->actingAs($this->user)
            ->post(route('health.followup', $session->MaSL), ['improved' => 0]);

        $this->assertSame('emergency', $session->fresh()->outcome);
    }

    public function test_a_failed_follow_up_mail_keeps_the_due_date_for_a_retry(): void
    {
        $session = $this->selfCareSession(['follow_up_at' => now()->subHour()]);

        \Illuminate\Support\Facades\Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $sent = app(\App\Services\TriageFollowUpService::class)->dispatchDue();

        $this->assertSame(0, $sent, 'A failed send must not be counted.');
        $this->assertNotNull($session->fresh()->follow_up_at, 'The safety net must survive a mail failure.');
    }

    // --- Escalated note visibility ---

    public function test_an_escalated_pharmacist_note_reaches_the_customer(): void
    {
        $pharmacist = User::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);

        $session = TriageSession::create([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'pending_pharmacist',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Chờ dược sĩ xem.',
            'collected_data' => ['raw_symptom_text' => 'dau dau'],
        ]);

        $this->actingAs($pharmacist)->post(route('pharmacist.triage.escalate', $session->MaSL), [
            'pharmacist_note' => 'Anh/chị cần đến khám chuyên khoa thần kinh trong hôm nay.',
        ]);

        $this->assertSame('refer_doctor', $session->fresh()->outcome);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee('chuyên khoa thần kinh', false);
    }

    // --- Drug safety ---

    public function test_the_chatbot_cart_endpoint_enforces_drug_safety(): void
    {
        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['Paracetamol'],
            'consented_at' => now(),
        ]);

        $product = Product::factory()->create([
            'name' => 'Panadol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'stock_quantity' => 50,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/chatbot/cart/add', ['MaSP' => $product->MaSP, 'quantity' => 1])
            ->assertStatus(409)
            ->assertJson(['requires_confirmation' => true]);

        $this->assertEmpty(session('cart', []));

        $this->actingAs($this->user)
            ->postJson('/api/chatbot/cart/add', [
                'MaSP' => $product->MaSP,
                'quantity' => 1,
                'confirm_warning' => 1,
            ])
            ->assertOk();

        $this->assertArrayHasKey($product->MaSP, session('cart'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'drug_warning_overridden']);
    }

    public function test_a_one_character_allergy_does_not_block_the_catalog(): void
    {
        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['C'],
            'consented_at' => now(),
        ]);

        $product = Product::factory()->create([
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'stock_quantity' => 50,
        ]);

        $this->actingAs($this->user)->post(route('cart.add'), [
            'MaSP' => $product->MaSP,
            'quantity' => 1,
        ]);

        $this->assertSame([], session('drug_warnings', []));
        $this->assertArrayHasKey($product->MaSP, session('cart'));
    }

    public function test_a_real_allergy_still_blocks(): void
    {
        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['Paracetamol'],
            'consented_at' => now(),
        ]);

        $product = Product::factory()->create([
            'name' => 'Panadol Extra',
            'ingredients' => 'Paracetamol 500mg',
            'stock_quantity' => 50,
        ]);

        $this->actingAs($this->user)->post(route('cart.add'), [
            'MaSP' => $product->MaSP,
            'quantity' => 1,
        ]);

        $this->assertNotEmpty(session('drug_warnings', []));
        $this->assertEmpty(session('cart', []));
    }

    public function test_checkout_drug_check_stays_within_a_query_budget(): void
    {
        $cart = [];

        for ($i = 0; $i < 10; $i++) {
            $product = Product::factory()->create([
                'ingredients' => "Paracetamol{$i} 500mg",
                'stock_quantity' => 100,
            ]);
            $cart[$product->MaSP] = 1;
        }

        DrugContraindication::create([
            'ingredient' => 'paracetamol0',
            'condition_type' => 'pregnancy',
            'condition_value' => null,
            'severity' => 'relative',
            'warning_vi' => 'Thận trọng',
            'is_active' => true,
        ]);

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($this->user)->withSession(['cart' => $cart])->get(route('checkout.index'))->assertOk();

        // Was 61 before the interaction/contraindication tables were indexed in memory.
        $this->assertLessThan(20, $queries, "A 10-item checkout must not fan out; ran {$queries} queries.");
    }

    // --- Medication reminders ---

    public function test_a_multi_batch_order_line_produces_one_correct_reminder(): void
    {
        $product = Product::factory()->create(['usage' => 'x 2 lần/ngày', 'name' => 'Amoxicillin']);
        $order = $this->order();

        // FEFO splits 20 tablets across two batches.
        OrderItem::create(['MaDH' => $order->MaDH, 'MaSP' => $product->MaSP, 'quantity' => 18, 'unit_price' => 1, 'batch_number' => 'B1']);
        OrderItem::create(['MaDH' => $order->MaDH, 'MaSP' => $product->MaSP, 'quantity' => 2, 'unit_price' => 1, 'batch_number' => 'B2']);

        $created = app(MedicationReminderService::class)->createFromOrder($order->fresh());

        $reminders = MedicationReminder::where('MaDH', $order->MaDH)->get();

        $this->assertSame(1, $created, 'The return value must match the rows written.');
        $this->assertCount(1, $reminders);
        $this->assertSame(10, $reminders->first()->duration_days, '20 tablets at 2/day is a 10-day course.');
    }

    public function test_cancelling_one_course_leaves_the_other_running(): void
    {
        $product = Product::factory()->create(['usage' => 'x 1 lần/ngày']);

        $first = $this->reminder($product);
        $second = $this->reminder($product);

        $this->actingAs($this->user)->post(route('reminders.cancel', $first->MaNN));

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status, 'A refill course must survive cancelling the original.');
    }

    public function test_a_soft_deleted_product_does_not_cancel_an_active_course(): void
    {
        $product = Product::factory()->create(['usage' => 'x 1 lần/ngày']);
        $reminder = $this->reminder($product, ['next_remind_at' => now()->subMinute()]);

        $product->delete();

        app(MedicationReminderService::class)->dispatchDue();

        $this->assertSame('active', $reminder->fresh()->status);
    }

    public function test_long_usage_text_is_truncated_to_fit_the_column(): void
    {
        $product = Product::factory()->create([
            'usage' => '<p>' . str_repeat('Uống sau ăn 30 phút. ', 40) . '</p>',
        ]);
        $order = $this->order();
        OrderItem::create(['MaDH' => $order->MaDH, 'MaSP' => $product->MaSP, 'quantity' => 4, 'unit_price' => 1, 'batch_number' => 'B1']);

        app(MedicationReminderService::class)->createFromOrder($order->fresh());

        $dosage = MedicationReminder::where('MaDH', $order->MaDH)->value('dosage_text');

        $this->assertLessThanOrEqual(255, mb_strlen($dosage));
        $this->assertStringNotContainsString('<p>', $dosage);
    }

    // --- Admin report ---

    public function test_an_unparseable_date_filter_does_not_break_the_report(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.triageReports.index', ['date_from' => 'abc']))
            ->assertOk();
    }

    public function test_the_trend_chart_honours_the_active_filters(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'status' => 'active']);

        foreach (['emergency', 'self_care', 'self_care'] as $outcome) {
            TriageSession::create([
                'MaKH' => $this->user->MaKH,
                'entry_point' => 'page',
                'current_step' => 6,
                'status' => 'completed',
                'outcome' => $outcome,
                'collected_data' => ['raw_symptom_text' => 'dau dau'],
            ]);
        }

        $trend = $this->actingAs($admin)
            ->getJson(route('admin.triageReports.trendChart', ['outcome' => 'emergency', 'days' => 30]))
            ->json();

        $this->assertSame(1, array_sum($trend['data']));
    }

    public function test_the_average_wait_is_aggregated_in_sql(): void
    {
        $pharmacist = User::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);

        foreach ([30, 90, 150] as $minutes) {
            $session = TriageSession::create([
                'MaKH' => $this->user->MaKH,
                'entry_point' => 'page',
                'current_step' => 6,
                'status' => 'reviewed',
                'outcome' => 'pharmacist_review',
                'pharmacist_id' => $pharmacist->MaKH,
                'reviewed_at' => now()->subMinutes(10),
                'collected_data' => ['raw_symptom_text' => 'dau dau'],
            ]);

            TriageSession::where('MaSL', $session->MaSL)
                ->update(['created_at' => now()->subMinutes($minutes + 10)]);
        }

        $summary = app(\App\Services\TriageReportService::class)->getSummary([]);

        $this->assertSame(3, $summary['reviewed_count']);
        $this->assertSame(90.0, $summary['average_wait_minutes']);
    }

    public function test_the_outcome_chart_does_not_re_run_the_summary_queries(): void
    {
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        app(\App\Services\TriageReportService::class)->getOutcomeDistribution([]);

        // Was 4 (the whole getSummary) when only the outcome tally is needed.
        $this->assertSame(1, $queries);
    }

    // --- Admin user management ---

    public function test_a_pharmacist_survives_an_unrelated_profile_edit(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'status' => 'active']);
        $pharmacist = User::factory()->create(['role' => 'Pharmacist', 'status' => 'active']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $pharmacist->MaKH), [
                'name' => 'Dược sĩ đổi tên',
                'email' => $pharmacist->email,
                'role' => 'Pharmacist',
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($pharmacist->fresh()->isPharmacist());
    }

    // --- Helpers ---

    protected function startSession(): TriageSession
    {
        $this->actingAs($this->user)->get(route('health.check'));

        return TriageSession::where('MaKH', $this->user->MaKH)->inProgress()->latest('MaSL')->firstOrFail();
    }

    protected function answer(int $MaSL, int $step, string $answer): void
    {
        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $MaSL,
            'step' => $step,
            'answer' => $answer,
        ]);
    }

    /**
     * A finished wizard plus the follow-up round, which is where the answers that
     * describe an existing symptom rather than a new one arrive.
     *
     * @param array<int, array{round: int, question: string, answer: string}> $clarifyAnswers
     */
    protected function clarifiedSession(string $symptomText, array $clarifyAnswers): TriageSession
    {
        return TriageSession::create([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'collected_data' => [
                'raw_symptom_text' => $symptomText,
                'symptoms' => $symptomText,
                'duration_days' => 1,
                'medications_taken' => 'Chua dung gi',
                'history' => 'Khong co',
                'other_symptoms' => 'Khong co them',
                'skipped' => [],
                'ai_clarify_answers' => $clarifyAnswers,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, string>
     */
    protected function flagCodes(array $result): array
    {
        return collect($result['triggered_red_flags'] ?? [])->pluck('code')->all();
    }

    protected function selfCareSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống đủ nước.',
            'collected_data' => ['raw_symptom_text' => 'dau dau nhe'],
        ], $attributes));
    }

    protected function signedFollowUpUrl(TriageSession $session, bool $improved): string
    {
        return URL::temporarySignedRoute('health.followup.confirm', now()->addDays(7), [
            'MaSL' => $session->MaSL,
            'improved' => $improved ? 1 : 0,
        ]);
    }

    protected function order(): Order
    {
        return Order::create([
            'MaKH' => $this->user->MaKH,
            'customer_name' => 'Khách hàng',
            'shipping_address' => '1 Đường A',
            'shipping_phone' => '0900000000',
            'total_amount' => 1,
            'status' => 'Pending',
        ]);
    }

    protected function reminder(Product $product, array $attributes = []): MedicationReminder
    {
        return MedicationReminder::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'MaSP' => $product->MaSP,
            'dosage_text' => 'Uống 1 lần/ngày',
            'times_per_day' => 1,
            'duration_days' => 5,
            'start_date' => now()->toDateString(),
            'next_remind_at' => now()->addHour(),
            'status' => 'active',
        ], $attributes));
    }
}
