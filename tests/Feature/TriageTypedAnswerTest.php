<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\TriageSession;
use App\Models\User;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The typed answer must be the recorded answer.
 *
 * A step that offers quick-pick options renders them as submit buttons BEFORE the
 * free-text input. HTML's implicit submission picks the first submit button in tree
 * order, so pressing Enter in the text box submitted `option_answer=<first option>`
 * alongside the typed `answer` — and the controller preferred the option. The patient
 * typed one complaint and the wizard recorded a different one, which then drove the
 * whole triage. This is a correctness bug, not a nicety.
 */
class TriageTypedAnswerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);
    }

    protected function startSession(): TriageSession
    {
        $this->actingAs($this->user)->get(route('health.check'));

        return TriageSession::where('MaKH', $this->user->MaKH)->inProgress()->latest('MaSL')->firstOrFail();
    }

    public function test_the_form_default_button_does_not_carry_a_quick_pick_value(): void
    {
        $html = $this->actingAs($this->user)->get(route('health.check'))->assertOk()->getContent();

        $formStart = strpos($html, 'id="triage-form"');
        $this->assertNotFalse($formStart);

        $form = substr($html, $formStart);
        $form = substr($form, 0, strpos($form, '</form>'));

        preg_match_all('/<button[^>]*type="submit"[^>]*>/', $form, $buttons);
        $this->assertNotEmpty($buttons[0], 'The step form must have at least one submit button.');

        // The FIRST submit button in tree order is what Enter activates. It must not be
        // a quick-pick option, or typing an answer and pressing Enter silently records
        // the option instead.
        $this->assertStringNotContainsString(
            'option_answer',
            $buttons[0][0],
            'The default submit button must not carry a quick-pick value.'
        );
    }

    public function test_pressing_enter_records_the_typed_symptom_not_the_first_option(): void
    {
        $session = $this->startSession();

        // On Enter the browser now posts only the typed answer, because the form's
        // default submit button is unnamed. `option_answer` arrives solely from a click.
        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 1,
            'answer' => 'tôi bị tiêu chảy từ sáng nay',
        ]);

        $session->refresh();

        $this->assertSame('tôi bị tiêu chảy từ sáng nay', $session->collected_data['raw_symptom_text']);
        $this->assertSame('tôi bị tiêu chảy từ sáng nay', $session->collected_data['symptoms']);
    }

    public function test_the_wrong_symptom_would_have_driven_the_wrong_triage(): void
    {
        $session = $this->startSession();

        // The bug's real cost: the recorded symptom is what the rule engine matches on.
        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 1,
            'answer' => 'tôi bị tiêu chảy nhiều lần, mất nước',
        ]);

        $session->refresh();

        $matched = app(\App\Services\Triage\MedicalTriageService::class)
            ->detectSymptoms($session->collected_data['raw_symptom_text'])
            ->pluck('code')
            ->all();

        $this->assertContains('diarrhea', $matched);
        $this->assertNotContains('headache', $matched);
    }

    public function test_a_clicked_option_is_still_recorded_when_nothing_was_typed(): void
    {
        $session = $this->startSession();

        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 1,
            'answer' => '',
            'option_answer' => 'Tiêu chảy',
        ]);

        $this->assertSame('Tiêu chảy', $session->refresh()->collected_data['raw_symptom_text']);
    }

    public function test_a_clicked_option_still_wins_over_a_prefilled_box_on_the_same_step(): void
    {
        $session = $this->startSession();
        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 1,
            'answer' => 'đau đầu',
        ]);

        // Back prefills the box with the stored answer. Clicking a different option must
        // replace it, so the click cannot lose to the value it is meant to overwrite.
        $this->actingAs($this->user)->post(route('health.back'), [
            'triage_session_id' => $session->MaSL,
        ]);

        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 1,
            'answer' => 'đau đầu',
            'option_answer' => 'Tiêu chảy',
        ]);

        $this->assertSame('Tiêu chảy', $session->refresh()->collected_data['raw_symptom_text']);
    }

    public function test_the_typed_answer_wins_on_a_later_step_too(): void
    {
        $session = $this->startSession();
        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 1,
            'answer' => 'tôi bị ho',
        ]);

        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 2,
            'answer' => 'khoảng 5 ngày rồi',
        ]);

        // Step 2 parses a duration: 5 days, not the "Hôm nay" option's 1 day.
        $this->assertSame(5, $session->refresh()->collected_data['duration_days']);
    }

    public function test_every_step_form_keeps_the_unnamed_default_button(): void
    {
        $session = $this->startSession();

        // Walk the wizard and re-check the invariant on each step that offers options:
        // one regression on one step is enough to mis-record an answer.
        foreach ([1 => 'tôi bị ho', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'không có'] as $step => $answer) {
            $html = $this->actingAs($this->user)->get(route('health.check'))->assertOk()->getContent();

            $start = strpos($html, 'id="triage-form"');

            if ($start !== false) {
                $form = substr($html, $start);
                $form = substr($form, 0, strpos($form, '</form>'));

                preg_match_all('/<button[^>]*type="submit"[^>]*>/', $form, $buttons);

                $this->assertStringNotContainsString(
                    'option_answer',
                    $buttons[0][0] ?? '',
                    "Step {$step}: the default submit button must not carry a quick-pick value."
                );
            }

            $this->actingAs($this->user)->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => $step,
                'answer' => $answer,
            ]);
        }
    }
}
