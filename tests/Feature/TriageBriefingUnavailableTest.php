<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\Category;
use App\Models\TriageSession;
use App\Models\User;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the result page shows while the briefing is being written, and what it shows
 * when that write never lands.
 *
 * The defect this pins: the poller's stop() removed the skeleton and put nothing in
 * its place, so a dead worker left a gap where the explanation should be — a page
 * that reads as though it is still loading, forever. The advice banner is the
 * opposite case and must keep being removed outright: its text is already on the
 * page, so a notice there would be noise.
 */
class TriageBriefingUnavailableTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        config()->set('chatbot.ai.triage.briefing', true);

        $this->user = User::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    public function test_a_pending_briefing_ships_the_unavailable_notice_hidden(): void
    {
        $session = $this->completedSession();
        $this->queuedBriefing($session);

        $html = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee('triage-briefing-pending', false)
            ->assertSee('triage-briefing-unavailable', false)
            ->assertSee(__('messages.health.briefing_unavailable'), false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/id="triage-briefing-unavailable"[^>]*\shidden/',
            $html,
            'The notice must ship hidden so the poller can reveal it without a round trip.'
        );
    }

    /** The poller needs the id to unhide, and only for the briefing. */
    public function test_only_the_briefing_poll_job_carries_a_fallback_target(): void
    {
        $session = $this->completedSession();
        $this->queuedBriefing($session);
        AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $session->MaKH,
            'kind' => AiJob::KIND_TRIAGE_ADVICE,
            'status' => 'queued',
            'payload' => ['MaSL' => $session->MaSL],
        ]);

        $html = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('triage-briefing-unavailable', $html);
        // The advice banner's text is already on the page; a notice there is noise.
        $this->assertStringNotContainsString('triage-ai-unavailable', $html);
    }

    /** A briefing already attempted and abandoned shows the notice outright. */
    public function test_a_finished_attempt_without_a_briefing_shows_the_notice_visible(): void
    {
        $session = $this->completedSession(['ai_briefing_status' => 'failed']);

        $html = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_unavailable'), false)
            ->getContent();

        $this->assertStringNotContainsString('triage-briefing-pending', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/id="triage-briefing-unavailable"[^>]*\shidden/',
            $html,
            'Nothing is polling any more, so the notice must be visible immediately.'
        );
    }

    /** An emergency page was never due one, so it must not apologise for the absence. */
    public function test_an_emergency_page_shows_no_briefing_notice_at_all(): void
    {
        $session = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị hãy gọi ngay 115.',
        ]);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertDontSee(__('messages.health.briefing_unavailable'), false)
            ->assertDontSee('triage-briefing-pending', false);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function completedSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống đủ nước.',
            'safety_net' => 'Đi khám nếu nặng hơn.',
            'collected_data' => ['raw_symptom_text' => 'dau dau nhe', 'skipped' => []],
        ], $attributes));
    }

    /**
     * A briefing job the page will poll. `ai_briefing_status` stays null so
     * ensureBriefingJob() finds this row instead of queueing a second one.
     */
    protected function queuedBriefing(TriageSession $session): AiJob
    {
        return AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $session->MaKH,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'queued',
            'payload' => ['MaSL' => $session->MaSL],
        ]);
    }
}
