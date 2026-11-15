<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\PhienSangLoc;
use App\Services\AiJobService;
use App\Services\Triage\Ai\SangLocAiTomTatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Viết briefing chi tiết cho trang kết quả ngoài web request.
 *
 * Trì hoãn cùng lý do với việc viết lại lời khuyên: nó chạy sau hoanTat() và
 * chỉ chạm vào `ai_briefing`/`ai_briefing_status`, nên kết luận mà khách đọc
 * không thể đổi khi job này hoàn tất. Trang poll và tải lại khi điều đó xảy ra.
 *
 * Timeout khoan dung vì lệnh gọi xin sáu section cùng lúc và được đo vượt xa
 * budget đồng bộ của wizard.
 */
class XuLyTomTatSangLoc implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $uuid)
    {
    }

    public function handle(SangLocAiTomTatService $briefing, AiJobService $jobs): void
    {
        $job = AiJob::where('uuid', $this->uuid)->first();

        if ($job === null || $job->isFinished()) {
            return;
        }

        $jobs->markRunning($job);

        $phien = PhienSangLoc::find($job->payload['ma_sl'] ?? null);

        if ($phien === null) {
            $jobs->markFailed($job, 'triage session no longer exists');

            return;
        }

        try {
            // generate() không bao giờ throw và tự ghi trạng thái riêng, nên điều này báo
            // cáo việc seam có chạy hay không, chứ không phải model có tốt hay không.
            $briefing->sinhNoiDung($phien);

            $jobs->markDone($job, ['ai_briefing_status' => $phien->refresh()->ai_briefing_status]);
        } catch (\Throwable $exception) {
            Log::warning('Triage briefing job failed', [
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);

            $jobs->markFailed($job, $exception->getMessage());
        }
    }
}
