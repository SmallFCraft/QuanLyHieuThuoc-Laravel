<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\TriageSession;
use App\Services\AiJobService;
use App\Services\TriageAiBriefingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Write the detailed result-page briefing outside the request.
 *
 * Deferred for the same reason as the advice rewrite: it runs after finalize() and
 * touches only `ai_briefing`/`ai_briefing_status`, so the conclusion the customer
 * reads cannot change once this lands. The page polls and reloads when it does.
 *
 * The timeout is generous because the call asks for six sections at once and was
 * measured well past the wizard's synchronous budget.
 */
class ProcessTriageBriefing implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $uuid)
    {
    }

    public function handle(TriageAiBriefingService $briefing, AiJobService $jobs): void
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
            // generate() never throws and records its own status, so this reports
            // whether the seam ran, not whether the model was any good.
            $briefing->generate($session);

            $jobs->markDone($job, ['ai_briefing_status' => $session->refresh()->ai_briefing_status]);
        } catch (\Throwable $exception) {
            Log::warning('Triage briefing job failed', [
                'MaSL' => $session->MaSL,
                'error' => $exception->getMessage(),
            ]);

            $jobs->markFailed($job, $exception->getMessage());
        }
    }
}
