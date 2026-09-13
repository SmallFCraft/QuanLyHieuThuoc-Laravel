<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\User;
use App\Models\UserHealthProfile;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The triage wizard is a plain form flow — every step is a real POST that
 * redirects. These tests drive it the same way a browser with JavaScript disabled
 * would, which is the contract the feature promises.
 */
class HealthCheckFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(MedicalKnowledgeSeeder::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);
    }

    // --- Access control ---

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('health.check'))->assertRedirect(route('login'));
    }

    public function test_guest_still_sees_the_navbar_entry_point(): void
    {
        // Hiding the link would mean guests never learn the feature exists.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('health.check'), false);
    }

    public function test_authenticated_user_sees_the_first_question(): void
    {
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee('Bước 1/5')
            ->assertSee('triage-question', false)
            ->assertSee('role="progressbar"', false);
    }

    public function test_the_scope_notice_is_always_present_in_full_and_has_no_dismiss_control(): void
    {
        $html = $this->actingAs($this->user)
            ->get(route('health.check'))
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

    public function test_answering_a_step_advances_the_wizard(): void
    {
        $session = $this->startSession();

        $this->actingAs($this->user)
            ->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => 1,
                'answer' => 'đau đầu',
            ])
            ->assertRedirect(route('health.check'));

        $this->assertSame(2, $session->fresh()->current_step);
    }

    public function test_empty_answer_on_the_mandatory_first_step_is_rejected(): void
    {
        $session = $this->startSession();

        $this->actingAs($this->user)
            ->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => 1,
                'answer' => '',
            ])
            ->assertRedirect(route('health.check'))
            ->assertSessionHasErrors('answer');

        $this->assertSame(1, $session->fresh()->current_step, 'A rejected answer must not advance the step.');
    }

    public function test_back_returns_to_the_previous_step_and_keeps_the_answer(): void
    {
        $session = $this->startSession();
        $this->answer($session, 1, 'tiêu chảy');

        $this->actingAs($this->user)
            ->post(route('health.back'), ['triage_session_id' => $session->MaSL])
            ->assertRedirect(route('health.check'));

        $session = $session->fresh();
        $this->assertSame(1, $session->current_step);

        // The stored answer is pre-filled back into the input.
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertSee('value="tiêu chảy"', false);
    }

    public function test_skipping_a_step_is_recorded_as_skipped(): void
    {
        $session = $this->startSession();
        $this->answer($session, 1, 'ho');

        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->fresh()->MaSL,
            'step' => 2,
            'answer' => '',
            'skipped' => 1,
        ]);

        $this->assertContains('duration_days', $session->fresh()->collected_data['skipped']);
    }

    public function test_completing_all_steps_redirects_to_a_result_url(): void
    {
        $this->stockProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu, hạ sốt');

        $session = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $session = $session->fresh();

        $this->assertSame('completed', $session->status);
        $this->assertSame('self_care', $session->outcome);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.outcome_self_care'));
    }

    /**
     * The flagged card carries the drug-safety warning, so its amber rail has to
     * outlive both the hover rule and the print block. Two duplicate
     * `.health-product--flagged` rules in `@media print` used to cancel the rail:
     * the later `border-width/border-color` pair won and printed a plain grey box.
     */
    public function test_flagged_product_keeps_its_rail_on_hover_and_on_paper(): void
    {
        $this->stockProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu, hạ sốt');

        $session = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        $content = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        // Hover is (0,2,0) and would otherwise repaint the rail with .health-product's grey.
        $this->assertMatchesRegularExpression(
            '/\.health-product--flagged:hover\s*\{[^}]*border-left-color:\s*var\(--health-refer\)/s',
            $content
        );

        // Exactly one `!important` rail declaration: the old print block had a second
        // `.health-product--flagged` rule whose `border-width` + `border-color` pair
        // outranked the rail, so the flagged card printed as a plain grey box.
        $this->assertSame(1, substr_count($content, 'border-left: 6px solid var(--health-refer) !important'));
        $this->assertStringNotContainsString('border-color: #374151', $content);
    }

    public function test_reloading_mid_wizard_keeps_the_current_step(): void
    {
        $session = $this->startSession();
        $this->answer($session, 1, 'ho');
        $this->answer($session->fresh(), 2, 'hôm nay');

        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee('Bước 3/5');
    }

    public function test_restart_abandons_the_open_session(): void
    {
        $session = $this->startSession();
        $this->answer($session, 1, 'ho');

        $this->actingAs($this->user)
            ->post(route('health.restart'))
            ->assertRedirect(route('health.check'));

        $this->assertNull(TriageSession::find($session->MaSL));
    }

    public function test_answering_a_stale_session_reports_it_as_expired(): void
    {
        $session = $this->startSession();
        $session->update(['status' => 'completed']);

        $this->actingAs($this->user)
            ->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => 1,
                'answer' => 'đau đầu',
            ])
            ->assertRedirect(route('health.check'))
            ->assertSessionHas('error');
    }

    // --- Outcome rendering ---

    public function test_emergency_short_circuits_and_offers_only_the_115_call(): void
    {
        $session = $this->startSession();

        // Emergency ends questioning immediately: the very first answer finalises
        // instead of asking step 2.
        $this->actingAs($this->user)
            ->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => 1,
                'answer' => 'đau ngực dữ dội, khó thở',
            ])
            ->assertRedirect(route('health.result', $session->MaSL));

        $session = $session->fresh();
        $this->assertSame('emergency', $session->outcome);
        $this->assertSame([], $session->suggested_products);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee('tel:115', false)
            ->assertSee(__('messages.health.outcome_emergency'))
            ->assertSee(__('messages.health.no_products'));
    }

    public function test_referral_outcome_renders_no_product_block(): void
    {
        $this->stockProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Hạ sốt');

        $session = $this->runWizard([
            1 => 'bé 1 tuổi bị sốt cao',
            2 => '2-3 ngày',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        $this->assertContains($session->outcome, ['refer_doctor', 'emergency']);
        $this->assertSame([], $session->suggested_products);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.no_products'))
            ->assertDontSee(__('messages.health.products_title'));
    }

    public function test_self_care_shows_the_safety_net_above_the_product_list(): void
    {
        $this->stockProduct('Paracetamol 500mg', 'Paracetamol 500mg', 'Giảm đau đầu');

        $session = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        $html = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $safetyPos = strpos($html, __('messages.health.safety_net_title'));
        $productsPos = strpos($html, __('messages.health.products_title'));

        $this->assertNotFalse($safetyPos);
        $this->assertNotFalse($productsPos);
        $this->assertLessThan(
            $productsPos,
            $safetyPos,
            'The limits of self-care must be read before anything purchasable.'
        );
    }

    public function test_an_uncertain_case_offers_a_pharmacist_instead_of_queueing_itself(): void
    {
        $session = $this->runWizard([
            1 => 'dieu gi rat khong ro rang',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        // The AI/rule answer is the result; the queue is opt-in.
        $this->assertSame('pharmacist_review', $session->outcome);
        $this->assertSame('completed', $session->status);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.pharmacist_request_btn'))
            ->assertDontSee(__('messages.health.pharmacist_waiting'));
    }

    public function test_asking_for_a_pharmacist_shows_the_wait_estimate(): void
    {
        $session = $this->runWizard([
            1 => 'dieu gi rat khong ro rang',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        $this->actingAs($this->user)
            ->post(route('health.requestPharmacist', $session->MaSL))
            ->assertRedirect(route('health.result', $session->MaSL));

        $this->assertSame('pending_pharmacist', $session->fresh()->status);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.pharmacist_waiting'))
            ->assertDontSee(__('messages.health.pharmacist_request_btn'));
    }

    public function test_an_emergency_result_never_offers_the_pharmacist_channel(): void
    {
        $session = $this->runWizard([1 => 'tôi bị đau ngực dữ dội'])->fresh();

        $this->assertSame('emergency', $session->outcome);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertDontSee(__('messages.health.pharmacist_request_btn'));
    }

    public function test_a_user_cannot_request_a_pharmacist_on_someone_elses_session(): void
    {
        $session = $this->runWizard([
            1 => 'dieu gi rat khong ro rang',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ])->fresh();

        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);

        $this->actingAs($other)
            ->post(route('health.requestPharmacist', $session->MaSL))
            ->assertNotFound();

        $this->assertSame('completed', $session->fresh()->status);
    }

    // --- Result page ownership ---

    public function test_a_user_cannot_open_someone_elses_result(): void
    {
        $session = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);

        $this->actingAs($other)
            ->get(route('health.result', $session->MaSL))
            ->assertNotFound();
    }

    public function test_an_unfinished_session_has_no_result_page(): void
    {
        $session = $this->startSession();
        $this->answer($session, 1, 'ho');

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertNotFound();
    }

    // --- History ---

    public function test_history_is_empty_before_any_check(): void
    {
        $this->actingAs($this->user)
            ->get(route('health.history'))
            ->assertOk()
            ->assertSee(__('messages.health.history_empty'));
    }

    public function test_history_lists_completed_checks_only(): void
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

        $response = $this->actingAs($this->user)->get(route('health.history'))->assertOk();

        $response->assertSee(route('health.result', $finished->MaSL), false);
        $response->assertDontSee(route('health.result', $inProgress->MaSL), false);
    }

    public function test_history_only_shows_the_users_own_checks(): void
    {
        $mine = $this->runWizard([
            1 => 'đau đầu',
            2 => 'hôm nay',
            3 => 'chưa dùng gì',
            4 => 'không có',
            5 => 'không có thêm',
        ]);

        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);

        $this->actingAs($other)
            ->get(route('health.history'))
            ->assertOk()
            ->assertDontSee(route('health.result', $mine->MaSL), false);
    }

    // --- Health profile integration ---

    public function test_consented_profile_lets_the_wizard_skip_the_history_question(): void
    {
        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'chronic_conditions' => ['loét dạ dày'],
            'consented_at' => now(),
        ]);

        $session = $this->startSession();
        $this->answer($session, 1, 'đau đầu');
        $this->answer($session->fresh(), 2, 'hôm nay');
        $this->answer($session->fresh(), 3, 'chưa dùng gì');

        // Step 4 is answered by the profile, so step 5 comes next.
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee('Bước 5/5');
    }

    public function test_users_without_a_profile_see_the_prompt_to_create_one(): void
    {
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee(__('messages.health.profile_cta'));
    }

    // --- Rate limiting ---

    public function test_answer_endpoint_is_rate_limited(): void
    {
        $session = $this->startSession();

        // The route allows 20 requests per minute.
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($this->user)->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => 1,
                'answer' => 'đau đầu',
            ]);
        }

        $this->actingAs($this->user)
            ->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => 1,
                'answer' => 'đau đầu',
            ])
            ->assertStatus(429);
    }

    // --- Helpers ---

    protected function startSession(): TriageSession
    {
        $this->actingAs($this->user)->get(route('health.check'));

        return TriageSession::where('MaKH', $this->user->MaKH)->inProgress()->latest('MaSL')->firstOrFail();
    }

    protected function answer(TriageSession $session, int $step, string $answer): void
    {
        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
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
    protected function runWizard(array $answers): TriageSession
    {
        $session = $this->startSession();
        $id = $session->MaSL;

        foreach ($answers as $step => $answer) {
            $current = TriageSession::find($id);

            if ($current === null || $current->status !== 'in_progress') {
                break;
            }

            $this->answer($current, $step, $answer);
        }

        return TriageSession::findOrFail($id);
    }

    protected function stockProduct(string $name, string $ingredients, string $usage): Product
    {
        return Product::create([
            'MaDM' => $this->category->MaDM,
            'name' => $name,
            'ingredients' => $ingredients,
            'usage' => $usage,
            'price' => 5000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 50,
            'reorder_point' => 5,
            'batch_number' => 'B-' . uniqid(),
        ]);
    }
}
