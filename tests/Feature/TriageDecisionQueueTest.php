<?php

namespace Tests\Feature;

use App\Jobs\ProcessTriageEvaluation;
use App\Models\AiJob;
use App\Models\Category;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\User;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The outcome decision as background work.
 *
 * This exists because of a live bug: `evaluate()` makes the AI fact-extraction call,
 * which was measured at 11-30s against a provider whose synchronous budget was 8s. On
 * timeout the AI facts are dropped silently, so `shouldAskClarify()` had nothing to
 * read and the wizard jumped from "vòng 1/3" straight to the result — and a stated
 * duration of "mấy năm nay" was scored as one day.
 *
 * The invariant: the patient WAITS for the decision. Nothing is shown until the job
 * has decided, so the conclusion is never revised after they have read it.
 */
class TriageDecisionQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Category::create(['name' => 'Thuốc giảm đau - Hạ sốt', 'description' => 'Giảm đau, hạ sốt']);
        $this->seed(MedicalKnowledgeSeeder::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);

        Product::create([
            'MaDM' => Category::first()->MaDM,
            'name' => 'Paracetamol 500mg',
            'ingredients' => 'Paracetamol 500mg',
            'usage' => 'Giảm đau, hạ sốt. Uống 1-2 viên mỗi 4-6 giờ',
            'price' => 5000,
            'expiration_date' => now()->addYear(),
            'stock_quantity' => 100,
            'reorder_point' => 5,
            'batch_number' => 'B-' . uniqid(),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    protected function toolCall(array $arguments): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'function_call' => [
                        'name' => 'extract_triage_facts',
                        'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ]],
        ];
    }

    protected function startSession(): TriageSession
    {
        $this->actingAs($this->user)->get(route('health.check'));

        return TriageSession::where('MaKH', $this->user->MaKH)->inProgress()->latest('MaSL')->firstOrFail();
    }

    /**
     * Answer the five wizard steps. The last POST is the one that triggers a decision.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function answerAllSteps(TriageSession $session)
    {
        $answers = [1 => 'tôi bị đau đầu', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'không có', 5 => 'không có thêm'];
        $response = null;

        foreach ($answers as $step => $answer) {
            $current = TriageSession::find($session->MaSL);

            if ($current === null || $current->status !== 'in_progress') {
                break;
            }

            $response = $this->actingAs($this->user)->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => $step,
                'answer' => $answer,
            ]);
        }

        return $response;
    }

    public function test_the_last_answer_queues_the_decision_instead_of_calling_the_provider(): void
    {
        Queue::fake();

        $session = $this->startSession();
        $this->answerAllSteps($session);

        // The whole point: the 11-30s provider call does not happen in the request.
        Http::assertNothingSent();
        Queue::assertPushed(ProcessTriageEvaluation::class, 1);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        $this->assertSame('queued', $job->status);
        $this->assertSame($session->MaSL, $job->payload['MaSL']);

        // Undecided, not half-decided: no outcome is shown before the job runs.
        $session->refresh();
        $this->assertSame('in_progress', $session->status);
        $this->assertNull($session->outcome);
    }

    public function test_the_wizard_shows_a_waiting_state_while_the_worker_decides(): void
    {
        Queue::fake();

        $session = $this->startSession();
        $this->answerAllSteps($session);

        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee(__('messages.health.deciding_title'))
            ->assertSee(__('messages.health.deciding_body'))
            // The answers are shown back, so the wait is not a blank screen.
            ->assertSee('tôi bị đau đầu', false);
    }

    public function test_reloading_while_a_decision_is_queued_does_not_queue_a_second_one(): void
    {
        Queue::fake();

        $session = $this->startSession();
        $this->answerAllSteps($session);

        $this->actingAs($this->user)->get(route('health.check'))->assertOk();
        $this->actingAs($this->user)->get(route('health.check'))->assertOk();

        // A second job would mean a second paid provider call for one questionnaire.
        $this->assertSame(1, AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->count());
    }

    public function test_running_the_job_finalises_and_the_poll_points_at_the_result(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->toolCall([
            'symptom_codes' => ['headache'],
            'needs_more_info' => false,
            'missing_questions' => [],
        ]))]);
        config()->set('chatbot.ai.triage.fact_extraction', true);

        $session = $this->startSession();
        $this->answerAllSteps($session);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        (new ProcessTriageEvaluation($job->uuid))->handle(
            app(\App\Services\Triage\MedicalTriageService::class),
            app(\App\Services\AiJobService::class)
        );

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame('self_care', $session->outcome);

        $this->actingAs($this->user)
            ->getJson(route('health.decision', $job->uuid))
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('next', 'result')
            // Built by the controller, not the worker: route() inside a job resolves the
            // host from APP_URL, which sent the browser to a 404 on a non-default port.
            ->assertJsonPath('redirect_url', route('health.result', $session->MaSL));

        $this->assertArrayNotHasKey('result_url', (array) $job->refresh()->result);
    }

    public function test_a_follow_up_round_survives_the_queue_hop(): void
    {
        Queue::fake();
        config()->set('chatbot.ai.triage.fact_extraction', true);
        config()->set('chatbot.ai.triage.clarify_round', true);

        Http::fake(['*' => Http::response($this->toolCall([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị đau ở vùng nào của đầu?'],
        ]))]);

        $session = $this->startSession();
        $this->answerAllSteps($session);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        (new ProcessTriageEvaluation($job->uuid))->handle(
            app(\App\Services\Triage\MedicalTriageService::class),
            app(\App\Services\AiJobService::class)
        );

        $session->refresh();

        // Still open, with a round stored — this is the state the timeout used to skip.
        $this->assertSame('in_progress', $session->status);
        $this->assertSame(1, $session->ai_clarify_rounds);
        $this->assertSame(['Anh/chị đau ở vùng nào của đầu?'], $session->pendingClarifyQuestions());

        $this->actingAs($this->user)
            ->getJson(route('health.decision', $job->uuid))
            ->assertOk()
            ->assertJsonPath('next', 'clarify');

        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertOk()
            ->assertSee(__('messages.health.clarify_round', ['round' => 1, 'total' => 3]))
            ->assertSee('Anh/chị đau ở vùng nào của đầu?', false);
    }

    public function test_answering_a_round_queues_the_next_decision_rather_than_finalising(): void
    {
        Queue::fake();
        config()->set('chatbot.ai.triage.fact_extraction', true);
        config()->set('chatbot.ai.triage.clarify_round', true);

        $session = $this->startSession();
        $session->forceFill([
            'collected_data' => ['raw_symptom_text' => 'đau đầu', 'duration_days' => 1],
            'ai_clarify_rounds' => 1,
            'ai_meta' => ['clarify_questions' => ['Anh/chị đau ở vùng nào của đầu?']],
        ])->save();

        $this->actingAs($this->user)->post(route('health.clarify'), [
            'triage_session_id' => $session->MaSL,
            'clarify' => ['đau nửa đầu bên phải'],
        ]);

        // The old code called evaluate() here; when that timed out the round silently
        // became the last one.
        Http::assertNothingSent();
        Queue::assertPushed(ProcessTriageEvaluation::class, 1);

        $session->refresh();
        $this->assertSame('in_progress', $session->status);
        $this->assertSame('đau nửa đầu bên phải', $session->collected_data['ai_clarify_answers'][0]['answer']);
    }

    public function test_a_stranded_job_is_retired_so_the_reload_decides_inline(): void
    {
        Queue::fake();

        $session = $this->startSession();
        $this->answerAllSteps($session);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        $job->forceFill(['created_at' => now()->subSeconds(AiJob::SYNC_FALLBACK_SECONDS + 1)])->save();

        $this->actingAs($this->user)
            ->getJson(route('health.decision', $job->uuid))
            ->assertOk()
            ->assertJsonPath('stranded', true)
            ->assertJsonPath('redirect_url', route('health.check'));

        // Retired, not left queued: otherwise the reload finds the same job, renders the
        // waiting state again, polls, sees `stranded` again — a loop with no exit.
        $this->assertSame('failed', $job->refresh()->status);
    }

    public function test_a_reload_after_a_stranded_job_reaches_the_result(): void
    {
        Queue::fake();

        $session = $this->startSession();
        $this->answerAllSteps($session);

        AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)
            ->firstOrFail()
            ->forceFill(['created_at' => now()->subSeconds(AiJob::SYNC_FALLBACK_SECONDS + 1)])
            ->save();

        // With no worker at all, reloading the wizard has to finish the job itself.
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertRedirect(route('health.result', $session->MaSL));

        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_a_failed_job_sends_the_client_back_to_decide_inline(): void
    {
        Queue::fake();

        $session = $this->startSession();
        $this->answerAllSteps($session);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        $job->forceFill(['status' => 'failed', 'error' => 'worker died'])->save();

        $this->actingAs($this->user)
            ->getJson(route('health.decision', $job->uuid))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('redirect_url', route('health.check'));

        // The reload finishes the questionnaire in-request rather than queueing again:
        // one dead attempt is enough evidence that nothing is consuming the queue.
        $this->actingAs($this->user)
            ->get(route('health.check'))
            ->assertRedirect(route('health.result', $session->MaSL));

        $this->assertSame('completed', $session->fresh()->status);
        $this->assertSame(
            1,
            AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->count(),
            'A dead job must not spawn a replacement that will die the same way.'
        );
    }

    public function test_another_customer_cannot_poll_someone_elses_decision(): void
    {
        Queue::fake();

        $session = $this->startSession();
        $this->answerAllSteps($session);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);

        $this->actingAs($other)
            ->getJson(route('health.decision', $job->uuid))
            ->assertNotFound();
    }

    public function test_a_guest_cannot_poll_a_decision(): void
    {
        $this->getJson(route('health.decision', 'any-uuid'))->assertUnauthorized();
    }

    public function test_an_emergency_never_waits_on_a_worker(): void
    {
        Queue::fake();

        $session = $this->startSession();

        $this->actingAs($this->user)->post(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 1,
            'answer' => 'đau ngực dữ dội, khó thở',
        ]);

        $session->refresh();

        // The red flag is detected in plain PHP, so somebody describing chest pain is
        // told to call 115 in the same request — never after a queue hop.
        $this->assertSame('emergency', $session->outcome);
        $this->assertSame('completed', $session->status);
        Queue::assertNotPushed(ProcessTriageEvaluation::class);
    }

    public function test_the_decision_runs_inline_without_a_worker(): void
    {
        // No Queue::fake(): the `sync` driver runs the job during dispatch, which is
        // what a worker-less install does.
        $session = $this->startSession();
        $response = $this->answerAllSteps($session);

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertSame('self_care', $session->outcome);
        $response->assertRedirect(route('health.result', $session->MaSL));
    }

    public function test_the_ajax_answer_returns_the_next_stage_without_a_reload(): void
    {
        $session = $this->startSession();

        $response = $this->actingAs($this->user)->postJson(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 1,
            'answer' => 'tôi bị đau đầu',
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'question')
            ->assertJsonPath('allow_back', true)
            ->assertJsonStructure(['stage', 'answered_steps']);

        // The stage is server-rendered HTML: no Vietnamese medical string is rebuilt in
        // the client.
        $this->assertStringContainsString('triage-question', $response->json('stage'));
        $this->assertStringContainsString(__('messages.health.progress_label', ['current' => 2, 'total' => 5]), $response->json('stage'));
        $this->assertStringContainsString('tôi bị đau đầu', $response->json('stage'));
    }

    public function test_the_ajax_decision_response_is_a_waiting_stage_not_a_result(): void
    {
        Queue::fake();

        $session = $this->startSession();

        foreach ([1 => 'tôi bị đau đầu', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'không có'] as $step => $answer) {
            $this->actingAs($this->user)->post(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => $step,
                'answer' => $answer,
            ]);
        }

        $response = $this->actingAs($this->user)->postJson(route('health.answer'), [
            'triage_session_id' => $session->MaSL,
            'step' => 5,
            'answer' => 'không có thêm',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('mode', 'deciding')
            ->assertJsonStructure(['stage', 'triage_pending' => ['job_id', 'poll_url']]);

        $this->assertStringContainsString(__('messages.health.deciding_title'), $response->json('stage'));
    }

    public function test_an_ajax_validation_error_carries_the_field(): void
    {
        $session = $this->startSession();

        $this->actingAs($this->user)
            ->postJson(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => 1,
                'answer' => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.answer.0', 'Anh/chị vui lòng mô tả triệu chứng để em sàng lọc giúp nhé.');
    }

    public function test_an_ajax_stale_step_offers_a_recovery_url(): void
    {
        $session = $this->startSession();

        $this->actingAs($this->user)
            ->postJson(route('health.answer'), [
                'triage_session_id' => $session->MaSL,
                'step' => 4,
                'answer' => 'gì đó',
            ])
            ->assertStatus(409)
            ->assertJsonPath('redirect_url', route('health.check'));
    }
}
