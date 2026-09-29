<?php

namespace App\Services;

use App\Jobs\ProcessChatbotMessage;
use App\Jobs\ProcessTriageAdviceRewrite;
use App\Jobs\ProcessTriageBriefing;
use App\Jobs\ProcessTriageEvaluation;
use App\Models\AiJob;
use App\Models\TriageSession;
use App\Services\Triage\Ai\TriageAiAdviceService;
use App\Services\Triage\Ai\TriageAiBriefingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hands AI work to a queue worker and reports its state back to the browser.
 *
 * The point is latency, not throughput: an AI call can hold a PHP process for the
 * full AI_TIMEOUT (plus the fallback provider), and the dev server runs a single
 * process, so one chat message blocks the whole site. Moving the call out of the
 * request keeps every page responsive.
 *
 * The queue driver decides the shape of the response, not this class: on `sync`
 * the job has already finished by the time dispatch() returns, so the caller can
 * answer in the same request. That is what makes the test suite and a
 * worker-less install behave identically to the old synchronous code.
 */
class AiJobService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array<string, mixed>  $context  Already-built chat context. Stored as-is:
     *                                         it holds the same message rows that are in `chat_messages` anyway, and
     *                                         CleanupAiJobs wipes it after 24h.
     * @param  bool  $sync  Run the job in this request instead of queueing it. Used when
     *                      the browser detected that no worker picked the previous job up.
     */
    public function dispatchChat(
        int $userId,
        string $message,
        array $context,
        string $sessionId,
        bool $sync = false
    ): AiJob {
        $job = AiJob::create([
            'uuid' => (string) Str::uuid(),
            'MaKH' => $userId,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'queued',
            'payload' => [
                'message' => $message,
                'context' => $context,
                'session_id' => $sessionId,
            ],
        ]);

        $this->audit($job, 'ai_job_dispatched');

        if ($sync) {
            ProcessChatbotMessage::dispatchSync($job->uuid);
        } else {
            ProcessChatbotMessage::dispatch($job->uuid);
        }

        return $job->refresh();
    }

    /**
     * Defer the outcome decision for a completed questionnaire.
     *
     * The payload is only the session id: the service reads the answers from the row.
     * The patient waits on this job, so the conclusion is never revised after they
     * have read it — see ProcessTriageEvaluation.
     */
    public function dispatchEvaluation(TriageSession $session): AiJob
    {
        $job = AiJob::create([
            'uuid' => (string) Str::uuid(),
            'MaKH' => $session->MaKH,
            'kind' => AiJob::KIND_TRIAGE_EVALUATION,
            'status' => 'queued',
            'payload' => ['MaSL' => $session->MaSL],
        ]);

        // Queued, not run inline: the caller falls back to deciding in-request when
        // polling shows no worker ever picked the job up.
        ProcessTriageEvaluation::dispatch($job->uuid);

        return $job->refresh();
    }

    /**
     * Defer the advice rewrite for a session that is already finalised.
     *
     * The payload is only the session id: no customer free text, no profile field.
     * TriageAiAdviceService reads what it needs from the row itself.
     */
    public function dispatchAdviceRewrite(TriageSession $session): AiJob
    {
        $job = AiJob::create([
            'uuid' => (string) Str::uuid(),
            'MaKH' => $session->MaKH,
            'kind' => AiJob::KIND_TRIAGE_ADVICE,
            'status' => 'queued',
            'payload' => ['MaSL' => $session->MaSL],
        ]);

        ProcessTriageAdviceRewrite::dispatch($job->uuid);

        return $job->refresh();
    }

    /**
     * Queue the rewrite only when this session is actually eligible for one, and
     * never let a queue failure cost the patient their result.
     */
    public function dispatchAdviceRewriteIfEligible(TriageSession $session): ?AiJob
    {
        if (! app(TriageAiAdviceService::class)->isEligible($session)) {
            return null;
        }

        try {
            return $this->dispatchAdviceRewrite($session);
        } catch (\Throwable $exception) {
            Log::warning('Triage advice rewrite skipped', [
                'MaSL' => $session->MaSL,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Defer the detailed result-page briefing for a finalised session.
     *
     * Separate job from the advice rewrite on purpose: this call asks for six
     * sections and a product pick, so it is much slower, and the two must not share
     * a failure. The payload is only the session id.
     */
    public function dispatchBriefing(TriageSession $session): AiJob
    {
        $job = AiJob::create([
            'uuid' => (string) Str::uuid(),
            'MaKH' => $session->MaKH,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'queued',
            'payload' => ['MaSL' => $session->MaSL],
        ]);

        ProcessTriageBriefing::dispatch($job->uuid);

        return $job->refresh();
    }

    /**
     * Queue the briefing only when the session is eligible, and never let a queue
     * failure cost the patient their result.
     */
    public function dispatchBriefingIfEligible(TriageSession $session): ?AiJob
    {
        if (! app(TriageAiBriefingService::class)->isEligible($session)) {
            return null;
        }

        try {
            return $this->dispatchBriefing($session);
        } catch (\Throwable $exception) {
            Log::warning('Triage briefing skipped', [
                'MaSL' => $session->MaSL,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Job state for the browser, scoped to its owner.
     *
     * The uuid is a public identifier, not a capability token, so ownership is
     * re-checked here rather than trusted from the URL.
     *
     * @return array<string, mixed>|null null when the job does not exist or belongs
     *                                   to someone else — both answer 404.
     */
    public function poll(string $uuid, int $userId): ?array
    {
        $job = AiJob::where('uuid', $uuid)->where('MaKH', $userId)->first();

        if ($job === null) {
            return null;
        }

        return [
            'status' => $job->status,
            'result' => $job->status === 'done' ? $job->result : null,
            'error' => $job->status === 'failed' ? ($job->error ?: null) : null,
            // Lets the browser stop polling a worker that was never started. What it does
            // next is the caller's choice: chat retries synchronously, but the triage
            // result page cannot — its briefing takes ~60-90s, far past a web request —
            // so it swaps in a "not available this time" notice instead.
            'stranded' => $job->isStranded(),
            'elapsed_ms' => $job->created_at === null
                ? 0
                : (int) round(($job->finished_at ?? now())->diffInMilliseconds($job->created_at, true)),
        ];
    }

    /**
     * Retire a job the browser gave up on, so the row does not stay `queued`
     * forever and a late worker cannot post a second answer for the same message.
     */
    public function abandon(AiJob $job, string $reason): void
    {
        $this->retire($job, 'failed', $reason, 'AI job fell back to sync', 'ai_job_sync_fallback');
    }

    /**
     * Retire a turn the customer pressed stop on.
     *
     * `cancelled`, not `failed`: a late poll must not paint an error the customer never
     * caused, and ProcessChatbotMessage re-reads the row after the provider answers, so
     * a stopped turn never reaches `chat_messages` — the transcript keeps matching what
     * the customer actually saw.
     */
    public function cancel(AiJob $job): void
    {
        $this->retire(
            $job,
            'cancelled',
            'customer stopped the request',
            'AI job cancelled by customer',
            'ai_job_cancelled'
        );
    }

    protected function retire(
        AiJob $job,
        string $status,
        string $reason,
        string $logMessage,
        string $auditAction
    ): void {
        if ($job->isFinished()) {
            return;
        }

        // The queue entry goes with it. Left pending, a worker starting later finds the
        // row already finished and returns early — harmless for chat, which has already
        // answered synchronously, but it accumulates dead rows in `jobs` forever.
        $this->discardQueuedWork($job->uuid);

        $job->forceFill([
            'status' => $status,
            'error' => $reason,
            'finished_at' => now(),
        ])->save();

        Log::warning($logMessage, [
            'uuid' => $job->uuid,
            'kind' => $job->kind,
            'reason' => $reason,
        ]);

        $this->audit($job, $auditAction);
    }

    /**
     * Drop the pending queue entry carrying this uuid, so a retired AiJob and the queue
     * agree. Only the database driver stores rows we can reach; on `sync` there is
     * nothing pending by definition.
     */
    public function discardQueuedWork(string $uuid): int
    {
        $connection = (string) config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return 0;
        }

        // The uuid is embedded in the serialized job payload, and it is unique.
        return DB::table((string) config("queue.connections.{$connection}.table", 'jobs'))
            ->where('payload', 'like', '%'.$uuid.'%')
            ->delete();
    }

    public function markRunning(AiJob $job): void
    {
        $job->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'attempts' => $job->attempts + 1,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markDone(AiJob $job, array $result): void
    {
        $job->forceFill([
            'status' => 'done',
            'result' => $result,
            'finished_at' => now(),
        ])->save();
    }

    public function markFailed(AiJob $job, string $error): void
    {
        $job->forceFill([
            'status' => 'failed',
            // Truncated: a provider can return a whole HTML error page.
            'error' => Str::limit($error, 1000),
            'finished_at' => now(),
        ])->save();

        $this->audit($job, 'ai_job_failed');
    }

    /**
     * Status, kind and timing only — never the payload or the customer's words.
     */
    protected function audit(AiJob $job, string $action): void
    {
        try {
            $this->auditLogService->log(
                action: $action,
                modelType: AiJob::class,
                modelId: $job->MaAJ,
                oldValues: null,
                newValues: [
                    'MaKH' => $job->MaKH,
                    'kind' => $job->kind,
                    'status' => $job->status,
                    'attempts' => $job->attempts,
                ],
                // Written from a queue worker as often as from a request, so the
                // owner comes from the job row rather than the guard.
                userId: $job->MaKH
            );
        } catch (\Throwable $exception) {
            Log::warning('AI job audit failed', [
                'uuid' => $job->uuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
