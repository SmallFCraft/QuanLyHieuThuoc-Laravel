<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\PhienSangLoc;
use App\Services\AiJobService;
use App\Services\Triage\Ai\SangLocAiVietLaiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Viết lại kết quả triage đã hoàn thành, ngoài web request.
 *
 * An toàn để trì hoãn chính xác vì nó chạy *sau* hoanTat(): nó ghi
 * `ai_advice`/`ai_safety_net`/`ai_status` và không bao giờ chạm vào `outcome`,
 * `advice_given` hoặc `safety_net`. Kết luận y mà khách đọc trên trang kết quả
 * đã là cuối cùng và không thể đổi khi job hoàn tất.
 *
 * Đó là ranh giới: trích duyệt sự thật giữ đồng bộ vì nó nuôi vào
 * severerRule() và do đó vào kết quả. Đổi một kết luận sau khi bệnh nhân đã đọc
 * là không được chấp nhận, nên chỉ seam này được trì hoãn.
 */
class XuLyVietLaiLoiKhuyen implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $uuid)
    {
    }

    public function handle(SangLocAiVietLaiService $advice, AiJobService $jobs): void
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
            // rewrite() không bao giờ throw và tự ghi ai_status riêng, nên điều này báo
            // cáo việc seam có chạy hay không, chứ không phải model có tốt hay không.
            $advice->vietLai($phien);

            $jobs->markDone($job, ['ai_status' => $phien->refresh()->ai_status]);
        } catch (\Throwable $exception) {
            Log::warning('Triage advice rewrite job failed', [
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);

            $jobs->markFailed($job, $exception->getMessage());
        }
    }
}
