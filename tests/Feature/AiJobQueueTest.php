<?php

namespace Tests\Feature;

use App\Jobs\ProcessChatbotMessage;
use App\Models\AiJob;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\ChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The chatbot turn as background work.
 *
 * The invariant under test: no AI call happens inside the web request when a worker
 * is available, the customer still gets an answer when one is not, and a job is only
 * ever readable by the customer who created it.
 */
class AiJobQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'User', 'status' => 'active']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function reply(array $overrides = []): array
    {
        return array_merge([
            'success' => true,
            'response' => 'Dạ, em đã ghi nhận câu hỏi.',
            'products' => [],
            'categories' => [],
            'show_disclaimer' => false,
        ], $overrides);
    }

    protected function fakeService(array $reply): void
    {
        $this->mock(ChatbotService::class, function (MockInterface $mock) use ($reply) {
            $mock->shouldReceive('processMessage')->andReturn($reply);
        });
    }

    public function test_a_worker_takes_the_turn_and_the_request_returns_immediately(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $response = $this->actingAs($this->user)->postJson('/api/chatbot/message', [
            'message' => 'Tôi cần tư vấn thuốc cảm',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonStructure(['job_id', 'poll_url', 'session_id']);

        // The whole point: the provider is not called in the web request.
        Http::assertNothingSent();
        Queue::assertPushed(ProcessChatbotMessage::class, 1);

        $job = AiJob::firstOrFail();
        $this->assertSame('queued', $job->status);
        $this->assertSame(AiJob::KIND_CHAT, $job->kind);
        $this->assertSame((int) $this->user->MaKH, (int) $job->MaKH);
        $this->assertSame('Tôi cần tư vấn thuốc cảm', $job->payload['message']);
    }

    public function test_no_transcript_row_is_written_before_the_job_runs(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->postJson('/api/chatbot/message', [
            'message' => 'Tôi bị đau đầu',
        ])->assertStatus(202);

        // A message the model has not answered yet must not appear in the thread,
        // otherwise a reload shows a question with no reply.
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_running_the_job_stores_the_turn_and_the_result(): void
    {
        Queue::fake();
        $this->fakeService($this->reply(['response' => 'Dạ, anh/chị uống nhiều nước nhé.']));

        $this->actingAs($this->user)->postJson('/api/chatbot/message', [
            'message' => 'Tôi bị đau đầu',
        ])->assertStatus(202);

        $job = AiJob::firstOrFail();
        (new ProcessChatbotMessage($job->uuid))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $job->refresh();

        $this->assertSame('done', $job->status);
        $this->assertSame('Dạ, anh/chị uống nhiều nước nhé.', $job->result['response']);
        $this->assertNotNull($job->finished_at);

        $this->assertDatabaseHas('chat_messages', [
            'MaKH' => $this->user->MaKH,
            'role' => 'user',
            'content' => 'Tôi bị đau đầu',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'MaKH' => $this->user->MaKH,
            'role' => 'assistant',
            'content' => 'Dạ, anh/chị uống nhiều nước nhé.',
        ]);
    }

    public function test_polling_a_finished_job_returns_the_answer(): void
    {
        Queue::fake();
        $this->fakeService($this->reply(['response' => 'Dạ, em trả lời đây ạ.']));

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        (new ProcessChatbotMessage($jobId))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $this->actingAs($this->user)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('success', true)
            ->assertJsonPath('response', 'Dạ, em trả lời đây ạ.');
    }

    public function test_polling_an_unfinished_job_reports_progress_without_a_result(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $this->actingAs($this->user)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('stranded', false)
            ->assertJsonMissingPath('response');
    }

    public function test_another_customer_cannot_poll_someone_elses_job(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);

        // The uuid is a public id, not a capability token.
        $this->actingAs($other)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertNotFound();
    }

    public function test_a_guest_cannot_poll(): void
    {
        $job = AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $this->user->MaKH,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'queued',
        ]);

        $this->getJson("/api/ai-jobs/{$job->uuid}")
            ->assertStatus(401)
            ->assertJsonPath('require_login', true);
    }

    public function test_a_thrown_job_is_recorded_as_failed_and_stays_traceable(): void
    {
        Queue::fake();

        $this->mock(ChatbotService::class, function (MockInterface $mock) {
            $mock->shouldReceive('processMessage')->andThrow(new \RuntimeException('AI provider failed'));
        });

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Chatbot đang lỗi à?'])
            ->json('job_id');

        (new ProcessChatbotMessage($jobId))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $job = AiJob::where('uuid', $jobId)->firstOrFail();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('AI provider failed', $job->error);

        // Support must still be able to see what was asked.
        $this->assertDatabaseHas('chat_messages', [
            'MaKH' => $this->user->MaKH,
            'role' => 'user',
            'content' => 'Chatbot đang lỗi à?',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'MaKH' => $this->user->MaKH,
            'role' => 'system',
            'content' => 'Không thể xử lý tin nhắn do lỗi hệ thống.',
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('success', false);
    }

    public function test_a_job_nobody_picked_up_is_reported_as_stranded(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $job = AiJob::where('uuid', $jobId)->firstOrFail();
        $job->forceFill(['created_at' => now()->subSeconds(AiJob::SYNC_FALLBACK_SECONDS + 1)])->save();

        $this->actingAs($this->user)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('stranded', true);
    }

    public function test_a_running_job_is_never_stranded_however_slow_the_model_is(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        // A slow provider must not be retried: that would send the same message twice.
        AiJob::where('uuid', $jobId)->firstOrFail()->forceFill([
            'status' => 'running',
            'created_at' => now()->subMinutes(2),
        ])->save();

        $this->actingAs($this->user)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('stranded', false);
    }

    /**
     * A job left behind by a worker that never started. Created directly because
     * Queue::fake() would also intercept the synchronous retry under test.
     */
    protected function strandedJob(): AiJob
    {
        $job = AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $this->user->MaKH,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'queued',
            'payload' => ['message' => 'Tôi bị ho', 'context' => [], 'session_id' => 'test-session'],
        ]);

        $job->forceFill(['created_at' => now()->subSeconds(AiJob::SYNC_FALLBACK_SECONDS + 1)])->save();

        return $job->refresh();
    }

    public function test_the_sync_retry_answers_in_the_request_and_retires_the_stranded_job(): void
    {
        $this->fakeService($this->reply(['response' => 'Dạ, em trả lời trực tiếp ạ.']));
        $stranded = $this->strandedJob();

        $response = $this->actingAs($this->user)->postJson('/api/chatbot/message', [
            'message' => 'Tôi bị ho',
            'sync' => true,
            'abandon_job_id' => $stranded->uuid,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('response', 'Dạ, em trả lời trực tiếp ạ.');

        $stranded->refresh();
        $this->assertSame('failed', $stranded->status);
        $this->assertStringContainsString('no worker', $stranded->error);
    }

    public function test_the_sync_retry_cannot_retire_another_customers_job(): void
    {
        $this->fakeService($this->reply());
        $victim = $this->strandedJob();

        $attacker = User::factory()->create(['role' => 'User', 'status' => 'active']);

        $this->actingAs($attacker)->postJson('/api/chatbot/message', [
            'message' => 'Xin chào',
            'sync' => true,
            'abandon_job_id' => $victim->uuid,
        ])->assertOk();

        $this->assertSame('queued', $victim->refresh()->status);
    }

    public function test_a_rejected_message_writes_nothing_and_needs_no_worker(): void
    {
        Queue::fake();
        $this->fakeService([
            'success' => false,
            'response' => 'Xin chào! Bạn cần tìm thuốc hay tư vấn triệu chứng gì ạ?',
            'validation_error' => true,
        ]);

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'aaaaaa'])
            ->json('job_id');

        (new ProcessChatbotMessage($jobId))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $this->assertSame('done', AiJob::where('uuid', $jobId)->firstOrFail()->status);
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_stopping_a_turn_retires_the_job_and_keeps_the_answer_out_of_the_thread(): void
    {
        Queue::fake();
        $this->fakeService($this->reply(['response' => 'Câu trả lời không ai còn chờ.']));

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $this->actingAs($this->user)
            ->deleteJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        // The worker was already mid-call when stop was pressed. Its answer must not
        // become a reply to a question the customer withdrew.
        (new ProcessChatbotMessage($jobId))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $this->assertSame('cancelled', AiJob::where('uuid', $jobId)->firstOrFail()->status);
        $this->assertSame(0, ChatMessage::count());
    }

    public function test_polling_a_stopped_turn_reports_cancelled_rather_than_an_error(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $this->actingAs($this->user)->deleteJson("/api/ai-jobs/{$jobId}")->assertOk();

        // A poll still in flight must not paint a failure the customer never caused.
        $this->actingAs($this->user)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('cancelled', true)
            ->assertJsonMissingPath('error');
    }

    public function test_a_finished_turn_cannot_be_rewritten_by_a_late_stop(): void
    {
        Queue::fake();
        $this->fakeService($this->reply(['response' => 'Dạ, em đã trả lời rồi ạ.']));

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        (new ProcessChatbotMessage($jobId))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $this->actingAs($this->user)->deleteJson("/api/ai-jobs/{$jobId}")->assertOk();

        // The answer is already on screen and in the transcript; relabelling it
        // cancelled would make the reload disagree with what the customer saw.
        $this->assertSame('done', AiJob::where('uuid', $jobId)->firstOrFail()->status);
    }

    public function test_another_customer_cannot_stop_someone_elses_turn(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);

        $this->actingAs($other)
            ->deleteJson("/api/ai-jobs/{$jobId}")
            ->assertNotFound();

        $this->assertSame('queued', AiJob::where('uuid', $jobId)->firstOrFail()->status);
    }

    public function test_a_guest_cannot_stop_a_turn(): void
    {
        $job = AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $this->user->MaKH,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'queued',
        ]);

        $this->deleteJson("/api/ai-jobs/{$job->uuid}")
            ->assertStatus(401)
            ->assertJsonPath('require_login', true);
    }

    public function test_the_payload_carries_no_health_profile_data(): void
    {
        Queue::fake();

        \App\Models\UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['penicillin'],
            'chronic_conditions' => ['loét dạ dày'],
            'consented_at' => now(),
        ]);

        $this->actingAs($this->user)->postJson('/api/chatbot/message', [
            'message' => 'Tôi bị đau đầu',
        ])->assertStatus(202);

        // ai_jobs.payload is plain json, so profile data must never reach it.
        $encoded = json_encode(AiJob::firstOrFail()->payload, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('penicillin', $encoded);
        $this->assertStringNotContainsString('loét dạ dày', $encoded);
    }

    public function test_a_job_is_run_only_once_even_if_delivered_twice(): void
    {
        Queue::fake();
        $this->fakeService($this->reply());

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $jobs = app(\App\Services\AiJobService::class);
        (new ProcessChatbotMessage($jobId))->handle(app(ChatbotService::class), $jobs);
        (new ProcessChatbotMessage($jobId))->handle(app(ChatbotService::class), $jobs);

        // A duplicate delivery must not post a second assistant reply.
        $this->assertSame(1, ChatMessage::where('role', 'assistant')->count());
    }

    public function test_cleanup_closes_a_job_whose_worker_died_and_drops_old_payloads(): void
    {
        $stuck = AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $this->user->MaKH,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'running',
            'payload' => ['message' => 'tôi bị đau đầu'],
        ]);
        $stuck->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $old = AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $this->user->MaKH,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'done',
            'payload' => ['message' => 'tôi bị sốt'],
        ]);
        $old->forceFill(['created_at' => now()->subHours(30)])->save();

        $recent = AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $this->user->MaKH,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'done',
        ]);

        $this->artisan('ai-jobs:cleanup')->assertSuccessful();

        $this->assertSame('failed', $stuck->refresh()->status);
        // The payload holds what the customer typed, so it must not sit in the DB.
        $this->assertNull(AiJob::find($old->MaAJ));
        $this->assertNotNull(AiJob::find($recent->MaAJ));
    }

    public function test_cleanup_drops_the_queue_entry_of_the_job_it_retires(): void
    {
        config()->set('queue.default', 'database');

        $job = AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $this->user->MaKH,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'queued',
            'payload' => ['MaSL' => 1],
        ]);
        $job->forceFill(['created_at' => now()->subMinutes(30)])->save();

        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => \App\Jobs\ProcessTriageBriefing::class,
                'data' => ['command' => 'uuid";s:36:"' . $job->uuid . '";'],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->artisan('ai-jobs:cleanup')->assertSuccessful();

        $this->assertSame('failed', $job->refresh()->status);
        // Left behind, a worker starting later finds the row finished, returns early,
        // and the patient's briefing disappears with nothing recorded.
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('jobs')->count());
    }

    public function test_a_job_queued_behind_a_running_one_is_waiting_not_stranded(): void
    {
        Queue::fake();

        // The worker is busy on a slow provider call, not absent.
        AiJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'MaKH' => $this->user->MaKH,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'running',
        ])->forceFill(['started_at' => now()->subSeconds(30)])->save();

        $jobId = $this->actingAs($this->user)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        AiJob::where('uuid', $jobId)->firstOrFail()
            ->forceFill(['created_at' => now()->subSeconds(AiJob::SYNC_FALLBACK_SECONDS + 1)])
            ->save();

        // Reported stranded, the browser would re-run this inline and block the
        // single-process dev server for the full provider budget a second time.
        $this->actingAs($this->user)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('stranded', false);
    }

    public function test_the_result_page_shows_the_conclusion_before_the_rewrite_lands(): void
    {
        // Only the rewrite is faked. The decision job must really run, because the
        // patient waits on it — faking it would leave the questionnaire undecided,
        // which is exactly the state this test exists to rule out.
        Queue::fake([\App\Jobs\ProcessTriageAdviceRewrite::class]);
        config()->set('chatbot.ai.triage.advice_rewrite', true);

        $category = \App\Models\Category::create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Giảm đau, hạ sốt',
        ]);
        $this->seed(\Database\Seeders\MedicalKnowledgeSeeder::class);
        \App\Models\Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau đầu, hạ sốt',
            'stock_quantity' => 50,
        ]);

        $this->user->forceFill(['birth_date' => now()->subYears(34)->toDateString()])->save();

        $this->actingAs($this->user)->get(route('health.check'));
        $session = \App\Models\TriageSession::where('MaKH', $this->user->MaKH)->inProgress()->firstOrFail();

        foreach ([1 => 'đau đầu', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'không có', 5 => 'không có thêm'] as $step => $answer) {
            $this->actingAs($this->user)->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => $step,
                'answer' => $answer,
            ]);
        }

        $session->refresh();

        // The medical conclusion is already persisted and readable; only the wording
        // is still queued. Deferring the rewrite must never defer the outcome.
        $this->assertSame('self_care', $session->outcome);
        $this->assertSame('completed', $session->status);
        $this->assertNull($session->ai_advice);
        $this->assertNotNull($session->advice_given);

        Queue::assertPushed(\App\Jobs\ProcessTriageAdviceRewrite::class, 1);

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.outcome_self_care'))
            // Honest about what is still coming, while the conclusion is already there.
            ->assertSee(__('messages.health.ai_pending'));

        // A reload while the rewrite is genuinely still queued keeps saying so — the
        // banner tracks the job, not a one-shot flash, so it survives the reload that
        // a 15-second wait invites.
        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.ai_pending'));

        // Once the rewrite lands there is nothing left to wait for.
        $session->forceFill(['ai_status' => 'ok', 'ai_advice' => 'Anh/chị nghỉ ngơi nhé.'])->save();

        $this->actingAs($this->user)
            ->get(route('health.result', $session->MaSL))
            ->assertOk()
            ->assertSee(__('messages.health.outcome_self_care'))
            ->assertDontSee(__('messages.health.ai_pending'));
    }

    public function test_the_triage_advice_rewrite_runs_as_a_job_without_touching_the_outcome(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => "LỜI KHUYÊN:\nAnh/chị nghỉ ngơi và uống nhiều nước trong 3 ngày nhé.",
            ]]],
        ])]);

        config()->set('chatbot.ai.triage.advice_rewrite', true);

        $session = \App\Models\TriageSession::create([
            'MaKH' => $this->user->MaKH,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống nhiều nước trong 3 ngày.',
            'collected_data' => ['raw_symptom_text' => 'toi bi sot nhe'],
        ]);

        $job = app(\App\Services\AiJobService::class)->dispatchAdviceRewrite($session);

        $this->assertSame(AiJob::KIND_TRIAGE_ADVICE, $job->kind);
        // No customer free text in the payload for this kind.
        $this->assertSame(['MaSL' => $session->MaSL], $job->payload);

        (new \App\Jobs\ProcessTriageAdviceRewrite($job->uuid))->handle(
            app(\App\Services\Triage\Ai\TriageAiAdviceService::class),
            app(\App\Services\AiJobService::class)
        );

        $session->refresh();

        $this->assertSame('done', $job->refresh()->status);
        $this->assertSame('ok', $session->ai_status);
        // The deterministic record and the medical conclusion are untouched.
        $this->assertSame('Nghỉ ngơi, uống nhiều nước trong 3 ngày.', $session->advice_given);
        $this->assertSame('self_care', $session->outcome);
    }
}
