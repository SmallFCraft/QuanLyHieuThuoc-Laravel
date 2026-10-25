<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\TriageSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The advice rewrite has no worker on a dev box, so the same stranded-job fallback
 * the briefing got must exist here too.
 *
 * Without it, teaching the result page to reload on `stranded` would reload forever:
 * the advice job is never retired and `ai_status` never leaves null, so every reload
 * would re-render the pending banner. The two changes ship together or neither does.
 */
class TriageAdviceFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected const SOURCE_ADVICE = "Nghỉ ngơi, uống nhiều nước.\nĐo lại nhiệt độ mỗi 4–6 giờ.";
    protected const SOURCE_SAFETY = 'Đi khám ngay nếu sốt trên 39 độ không hạ hoặc sốt quá 3 ngày.';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('chatbot.ai.triage.advice_rewrite', true);

        $this->user = User::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    protected function fakeRewrite(): void
    {
        Http::fake(['*/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => implode("\n", [
                'LỜI KHUYÊN:',
                'Anh/chị nghỉ ngơi, uống nhiều nước và đo lại nhiệt độ mỗi 4–6 giờ nhé.',
                '',
                'NGƯỠNG CẦN ĐI KHÁM:',
                'Anh/chị đi khám ngay nếu sốt trên 39 độ không hạ, hoặc sốt quá 3 ngày ạ.',
            ])]]],
        ])]);
    }

    protected function selfCareSession(array $attributes = []): TriageSession
    {
        return TriageSession::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => self::SOURCE_ADVICE,
            'safety_net' => self::SOURCE_SAFETY,
            'collected_data' => ['raw_symptom_text' => 'toi bi sot nhe'],
        ], $attributes));
    }

    protected function strandedAdvice(TriageSession $session, int $ageSeconds = 30): AiJob
    {
        $job = AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $session->MaKH,
            'kind' => AiJob::KIND_TRIAGE_ADVICE,
            'status' => 'queued',
            'payload' => ['MaSL' => $session->MaSL],
        ]);

        $job->forceFill(['created_at' => now()->subSeconds($ageSeconds)])->save();

        return $job->refresh();
    }

    public function test_a_stranded_advice_job_is_retired_without_blocking_the_page(): void
    {
        Http::preventStrayRequests();

        $session = $this->selfCareSession();
        $job = $this->strandedAdvice($session);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        // No provider call: the rewrite never runs inside the request.
        Http::assertNothingSent();

        $session->refresh();
        $this->assertNull($session->ai_status);
        $this->assertNull($session->ai_advice);

        // The job is retired rather than polled forever for a worker that never comes.
        $this->assertSame('failed', $job->refresh()->status);
        $this->assertStringContainsString('no worker picked', $job->error);

        // The pharmacist's authoritative text still renders.
        $this->assertSame(self::SOURCE_ADVICE, $session->advice_given);
    }

    /**
     * A job a worker may still pick up must stay a poll target: running it inline
     * would send the same rewrite request twice.
     */
    public function test_a_freshly_queued_advice_job_is_left_to_the_worker(): void
    {
        Http::fake();

        $session = $this->selfCareSession();
        $job = $this->strandedAdvice($session, 0);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        Http::assertNothingSent();
        $this->assertSame('queued', $job->refresh()->status);
        $this->assertNull($session->refresh()->ai_status);
    }

    /**
     * The key regression: once retired for being stranded, a second page load
     * does not retry or block. The advice job stays failed and the page loads immediately.
     */
    public function test_a_second_load_after_the_fallback_does_not_re_run_it(): void
    {
        Http::preventStrayRequests();

        $session = $this->selfCareSession();
        $this->strandedAdvice($session);

        $this->actingAs($this->user)->get(route('health.result', $session->MaSL))->assertOk();

        $session->refresh();
        $this->assertNull($session->ai_status);

        $second = $this->actingAs($this->user)->get(route('health.result', $session->MaSL));

        $second->assertOk();
        Http::assertNothingSent();

        // No pending job id reaches the page, so the poller never starts.
        $this->assertNull($second->viewData('aiJobId'));
    }

    public function test_a_previously_failed_advice_job_retries_once_and_caps_at_two_attempts(): void
    {
        $session = $this->selfCareSession(['ai_status' => 'failed']);

        AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $session->MaKH,
            'kind' => AiJob::KIND_TRIAGE_ADVICE,
            'status' => 'failed',
            'error' => 'timeout',
            'payload' => ['MaSL' => $session->MaSL],
        ]);

        $this->fakeRewrite();

        // Second load triggers the single retry.
        $this->actingAs($this->user)->get(route('health.result', $session->MaSL))->assertOk();

        $this->assertSame('ok', $session->refresh()->ai_status);
        $this->assertSame(2, AiJob::where('kind', AiJob::KIND_TRIAGE_ADVICE)->count());

        // Subsequent loads never retry further even if forced back to failed.
        $session->forceFill(['ai_status' => 'failed'])->save();
        Http::fake();
        $this->actingAs($this->user)->get(route('health.result', $session->MaSL))->assertOk();
        Http::assertNothingSent();
        $this->assertSame(2, AiJob::where('kind', AiJob::KIND_TRIAGE_ADVICE)->count());
    }
}
