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

    /** Job vẫn `running` sau khoảng thời gian này đã mất worker. */
    private const STUCK_MINUTES = 10;

    /** Payload giữ những gì khách đã gõ, nên nó không được ở trong DB mãi mãi. */
    private const RETENTION_HOURS = 24;

    public function handle(AiJobService $jobs): int
    {
        $stuck = 0;

        $stuckJobs = AiJob::whereIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->get();

        foreach ($stuckJobs as $job) {
            // Dòng hàng đợi đi trước, và việc xóa nó được log. Hưu trí dòng AiJob
            // trong khi công việc vẫn đang chờ trong `jobs` nghĩa là worker khởi động
            // sau sẽ thấy dòng đã xong, trả về sớm, và lượt của khách — hoặc briefing
            // của bệnh nhân — biến mất mà không có gì được ghi lại ở bất cứ đâu.
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
