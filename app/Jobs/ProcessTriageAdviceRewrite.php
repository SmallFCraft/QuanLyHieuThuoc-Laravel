<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\TriageSession;
use App\Services\AiJobService;
use App\Services\Triage\Ai\TriageAiAdviceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Rephrase a finished triage result outside the request.
 *
 * Safe to defer precisely because it runs *after* finalize(): it writes
 * `ai_advice`/`ai_safety_net`/`ai_status` and never touches `outcome`,
 * `advice_given` or `safety_net`. The medical conclusion the customer reads on the
 * result page is already final and cannot change once the job lands.
 *
 * That is the line: fact extraction stays synchronous because it feeds
 * severerRule() and therefore the outcome. Changing a conclusion after the patient
 * has read it is not acceptable, so only this seam is deferred.
 */
class ProcessTriageAdviceRewrite implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $uuid)
    {
    }

    public function handle(TriageAiAdviceService $advice, AiJobService $jobs): void
    {
        $job = AiJob::where('uuid', $this->uuid)->first();

        if ($job === null || $job->isFinished()) {
            return;
        }

        $jobs->markRunning($job);

        $session = TriageSession::find($job->payload['MaSL'] ?? null);

        if ($session === null) {
            $jobs->markFailed($job, 'triage session no longer exists');

            return;
        }

        try {
            // rewrite() never throws and records its own ai_status, so this reports
            // whether the seam ran, not whether the model was any good.
            $advice->rewrite($session);

            $jobs->markDone($job, ['ai_status' => $session->refresh()->ai_status]);
        } catch (\Throwable $exception) {
            Log::warning('Triage advice rewrite job failed', [
                'MaSL' => $session->MaSL,
                'error' => $exception->getMessage(),
            ]);

            $jobs->markFailed($job, $exception->getMessage());
        }
    }
}
