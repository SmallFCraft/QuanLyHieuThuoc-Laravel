<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\User;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TriageAiBriefingFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        config()->set('chatbot.ai.triage.briefing', true);

        $this->user = User::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    protected function fakeGhiDienGiaiResponse(): void
    {
        Http::fake([
            '*/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'ghi_dien_giai',
                                'arguments' => json_encode([
                                    'why' => 'Em kết luận tự chăm sóc vì triệu chứng đau đầu nhẹ mới xuất hiện và không có dấu hiệu nguy hiểm.',
                                    'possible_causes' => ['Căng thẳng hoặc thiếu ngủ thông thường.'],
                                    'self_care' => ['Nghỉ ngơi nơi yên tĩnh, uống đủ nước.'],
                                    'watch_for' => ['Đau đầu dữ dội đột ngột hoặc kèm nôn mửa.'],
                                    'see_doctor_when' => ['Đau đầu kéo dài trên 3 ngày.'],
                                    'ask_pharmacist' => ['Nên dùng thuốc giảm đau loại nào khi cần?'],
                                ], JSON_UNESCAPED_UNICODE),
                            ],
                        ]],
                    ],
                ]],
            ]),
        ]);
    }

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

    public function test_a_stranded_briefing_job_is_retired_and_the_page_loads_without_it(): void
    {
        $this->fakeGhiDienGiaiResponse();

        $session = $this->completedSession();

        $strandedJob = AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $session->MaKH,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'queued',
            'payload' => ['MaSL' => $session->MaSL],
        ]);
        $strandedJob->forceFill(['created_at' => now()->subSeconds(30)])->save();

        $this->assertTrue($strandedJob->refresh()->isStranded());

        $response = $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        // No provider call at all: the generation is not run in this request.
        Http::assertNothingSent();

        $session->refresh();
        $this->assertNull($session->ai_briefing_status);
        $this->assertNull($session->ai_briefing);

        // The job is retired, not left polling forever.
        $strandedJob->refresh();
        $this->assertSame('failed', $strandedJob->status);
        $this->assertStringContainsString('no worker picked', $strandedJob->error);

        // The result page still renders; the briefing pane falls back to the notice
        // instead of holding the request open for the provider.
        $response->assertSee(__('messages.health.briefing_unavailable'), false);
    }

    /**
     * A stranded job is retired without calling the provider, so even if the provider
     * is down, the request completes immediately with the unavailable notice.
     */
    public function test_a_stranded_job_retires_without_hitting_a_down_provider(): void
    {
        Http::fake(['*/v1/chat/completions' => Http::response('gateway down', 502)]);

        $session = $this->completedSession();
        $job = $this->strandedBriefing($session);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.briefing_unavailable'), false);

        // The job failed for lack of a worker, not because of the down provider.
        $this->assertSame('failed', $job->refresh()->status);
        $this->assertStringContainsString('no worker picked', $job->error);
        $this->assertNull($session->refresh()->ai_briefing_status);
    }

    /** An emergency page was never due a briefing, so the stale job is retired outright. */
    public function test_a_session_that_is_not_eligible_abandons_the_stranded_job(): void
    {
        Http::preventStrayRequests();

        $session = $this->completedSession([
            'outcome' => 'emergency',
            'advice_given' => 'Anh/chị gọi ngay 115.',
            'safety_net' => null,
        ]);
        $job = $this->strandedBriefing($session);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        Http::assertNothingSent();
        $this->assertSame('failed', $job->refresh()->status);
    }

    /**
     * A job young enough that a worker may still be about to run it stays a poll
     * target: the fallback must not run a briefing in-request while one is in flight.
     */
    public function test_a_freshly_queued_job_is_still_polled_and_not_run_inline(): void
    {
        Http::preventStrayRequests();

        $session = $this->completedSession();
        $job = $this->strandedBriefing($session, 0);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee('triage-briefing-pending', false);

        Http::assertNothingSent();
        $this->assertSame('queued', $job->refresh()->status);
        $this->assertNull($session->refresh()->ai_briefing_status);
    }

    public function test_a_previously_failed_briefing_recovers_on_second_view_when_provider_recovers(): void
    {
        $session = $this->completedSession();
        $session->forceFill(['ai_briefing_status' => 'failed'])->save();

        // One recorded failed job from the first attempt.
        AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $session->MaKH,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'failed',
            'error' => 'provider timeout',
            'payload' => ['MaSL' => $session->MaSL],
        ]);

        // Provider now succeeds.
        $this->fakeGhiDienGiaiResponse();

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk();

        // Second job dispatched and finished via sync driver.
        $this->assertSame(2, AiJob::where('kind', AiJob::KIND_TRIAGE_BRIEFING)->count());
        $this->assertSame('ok', $session->refresh()->ai_briefing_status);
        $this->assertIsArray($session->ai_briefing);
    }

    protected function strandedBriefing(TriageSession $session, int $ageSeconds = 30): AiJob
    {
        $job = AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $session->MaKH,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'queued',
            'payload' => ['MaSL' => $session->MaSL],
        ]);

        $job->forceFill(['created_at' => now()->subSeconds($ageSeconds)])->save();

        return $job->refresh();
    }
}
