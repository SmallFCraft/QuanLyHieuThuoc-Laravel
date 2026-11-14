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
 * Viet lai ket qua triage da hoan thanh, ngoai web request.
 *
 * An toan de tra lai chinh xac vi no chay *sau* hoanTat(): no ghi
 * `ai_advice`/`ai_safety_net`/`ai_status` va khong bao gio cham vao `outcome`,
 * `advice_given` hoac `safety_net`. Ket luan y ma khach doc tren trang ket qua
 * da la cuoi cung va khong the doi khi job hoan tat.
 *
 * Do la ranh gioi: trich duyet su tht giu dong bo vi no dinh duong vao
 * severerRule() va do den ket qua. Doi mot ket luan sau khi benh nhan da doc
 * la khong duoc chap nhan, nen chi seam nay duoc tra lai.
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
            // rewrite() khong bao gio throw va tu ghi ai_status rieng, nen dieu nay bao
            // cao viec seam co chay hay khong, chu khong phai model co tot hay khong.
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
