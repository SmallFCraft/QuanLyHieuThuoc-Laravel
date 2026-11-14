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
 * Viet briefing chi tiet cho trang ket qua ngoai web request.
 *
 * Tra lai cung ly do voi viec viet lai loi khuyen: no chay sau hoanTat() va
 * chi cham vao `ai_briefing`/`ai_briefing_status`, nen ket luan ma khach doc
 * khong the doi khi job nay hoan tat. Trang poll va tai lai khi dieu do xay ra.
 *
 * Timeout khoang rong vi loi goi xin sau section mot luc va duoc do cham qua
 * budget dong bo cua wizard.
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
            // generate() khong bao gio throw va tu ghi trang thai rieng, nen dieu nay bao
            // cao viec seam co chay hay khong, chu khong phai model co tot hay khong.
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
