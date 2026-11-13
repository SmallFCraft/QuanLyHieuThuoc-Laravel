<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\HoSoSucKhoe;
use App\Models\PhienSangLoc;
use App\Services\AiJobService;
use App\Services\Triage\SangLocYTeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Decide the outcome of a finished questionnaire, outside the web request.
 *
 * Measured against the configured provider, one fact-extraction call takes 11-30s
 * (median ~14s). With the old 8s synchronous budget it almost always timed out, and
 * a timeout is not merely slow — it silently drops the AI facts, so a patient who
 * said "may nam nay roi" was scored as one day, and the follow-up round that should
 * have come next never appeared because `canHoiLamRo()` had no AI result to
 * read. The wizard looked like it skipped from "vong 1/3" straight to the result.
 *
 * Deferring this is safe — and different from deferring the advice rewrite — because
 * the patient WAITS on it: the result page is not shown until the job has decided.
 * The conclusion is therefore never revised after they have read it, which is the
 * rule that keeps fact extraction out of the background elsewhere.
 *
 * The job decides nothing itself. It calls the same danhGia() / canHoiLamRo()
 * / hoanTat() the controller used to call inline.
 */
class XuLyDanhGiaSangLoc implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A retry would call the provider again and could hand the patient
     * a different conclusion for the same answers.
     */
    public int $tries = 1;

    /** Above the worst measured provider latency plus the fallback provider. */
    public int $timeout = 120;

    public function __construct(public string $uuid)
    {
    }

    public function handle(SangLocYTeService $triage, AiJobService $jobs): void
    {
        $job = AiJob::where('uuid', $this->uuid)->first();

        if ($job === null || $job->isFinished()) {
            return;
        }

        $phien = PhienSangLoc::find($job->payload['ma_sl'] ?? null);

        if ($phien === null || $phien->status !== 'in_progress') {
            $jobs->markFailed($job, 'triage session is no longer open');

            return;
        }

        $jobs->markRunning($job);

        // NhatKyKiemToanService reads Auth::id(); without this the worker writes audit rows
        // with a null customer, breaking the link to the person triaged.
        Auth::onceUsingId((int) $job->ma_nd);

        try {
            $result = $triage->danhGia($phien, HoSoSucKhoe::consentedFor((int) $job->ma_nd));

            if ($triage->canHoiLamRo($phien, $result)) {
                $triage->luuCChamLamRo($phien, $result);
                $jobs->markDone($job, ['next' => 'clarify']);

                return;
            }

            $phien = $triage->hoanTat($phien, $result);

            // Session id only, never a URL: a worker has no incoming request, so
            // route() would build the link from APP_URL and send the browser to the
            // wrong host or port. The controller turns this into a URL.
            $jobs->markDone($job, [
                'next' => 'result',
                'ma_sl' => $phien->ma_sl,
            ]);

            // Rephrasing is its own job: it runs after the conclusion is fixed and
            // must not hold the patient back from reading it.
            $jobs->dispatchAdviceRewriteIfEligible($phien);
        } catch (\Throwable $exception) {
            Log::error('Triage evaluation job failed', [
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);

            $jobs->markFailed($job, $exception->getMessage());
        }
    }
}
