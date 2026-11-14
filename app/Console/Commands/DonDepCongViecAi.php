<?php

namespace App\Console\Commands;

use App\Models\AiJob;
use App\Services\AiJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DonDepCongViecAi extends Command
{
    protected $signature = 'ai-jobs:cleanup';
    protected $description = 'Dọn bản ghi công việc AI đã xong và đóng các job bị treo';

    /** Job van `running` sau khoang thoi gian nay da mat worker. */
    private const STUCK_MINUTES = 10;

    /** Payload giu nhung gi khach da go, nen no khong duoc o trong DB mai mai. */
    private const RETENTION_HOURS = 24;

    public function handle(AiJobService $jobs): int
    {
        $stuck = 0;

        $stuckJobs = AiJob::whereIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->get();

        foreach ($stuckJobs as $job) {
            // Dong hang doi di truoc, va viec xoa no duoc log. Huu tro dong AiJob
            // trong khi cong viec van dang cho trong `jobs` nghia la worker khoi dong
            // sau se thay dong da xong, tra ve som, va luot cua khach — hoac briefing
            // cua benh nhan — bien mat ma khong ghi lai gi bat dau.
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
