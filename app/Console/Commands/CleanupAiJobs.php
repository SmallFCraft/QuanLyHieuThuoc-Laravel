<?php

namespace App\Console\Commands;

use App\Models\AiJob;
use App\Services\AiJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupAiJobs extends Command
{
    protected $signature = 'ai-jobs:cleanup';
    protected $description = 'Dọn bản ghi công việc AI đã xong và đóng các job bị treo';

    /** A job still `running` after this long lost its worker. */
    private const STUCK_MINUTES = 10;

    /** Payload holds what the customer typed, so it must not live in the DB forever. */
    private const RETENTION_HOURS = 24;

    public function handle(AiJobService $jobs): int
    {
        $stuck = 0;

        $stuckJobs = AiJob::whereIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->get();

        foreach ($stuckJobs as $job) {
            // The queue entry goes first, and its removal is logged. Retiring the AiJob
            // row while the work is still pending in `jobs` means a worker that starts
            // later finds the row already finished, returns early, and the customer's
            // turn — or a patient's briefing — vanishes with nothing recorded anywhere.
            $discarded = $jobs->discardQueuedWork($job->uuid);

            $job->forceFill([
                'status' => 'failed',
                'error' => 'worker died or was never started',
                'finished_at' => now(),
            ])->save();

            $stuck++;

            if ($discarded > 0) {
                Log::warning('AI job retired while its queue entry was still pending', [
                    'uuid' => $job->uuid,
                    'kind' => $job->kind,
                    'queue_rows_removed' => $discarded,
                ]);
            }
        }

        $deleted = AiJob::whereIn('status', ['done', 'failed', 'cancelled'])
            ->where('created_at', '<', now()->subHours(self::RETENTION_HOURS))
            ->delete();

        $this->info("Đã đóng {$stuck} job treo và xóa {$deleted} job cũ.");

        return Command::SUCCESS;
    }
}
