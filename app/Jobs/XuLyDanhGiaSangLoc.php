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
 * Quyet dinh ket qua cua mot bo cau hoi da hoan thanh, ngoai web request.
 *
 * Do tren nha cung cap da cau hinh, mot loi goi trich duyet su tht mat 11-30s
 * (trung vi ~14s). Voi ngan sach dong bo 8s cu thi gan nhu luon bi timeout, va
 * timeout khong chi la cham — no im lang lam mat cac su tht tu AI, nen nguoi benh
 * noi "may nam nay roi" bi tinh la mot ngay, va vong hoi lam ro theo sau can phai
 * xuat hien thi khong bao gio xuat hien vi `canHoiLamRo()` khong co ket qua AI de
 * doc. Tro ly trong nhu da nhay tu "vong 1/3" thang den trang ket qua.
 *
 * Tri hoan viec nay la an toan — va khac voi viec tri hoan viec viet lai loi khuyen —
 * vi nguoi benh DOI no: trang ket qua khong duoc hien cho toi khi cong viec da quyet
 * dinh. Vi vay ket luan khong bao gio bi sua lai sau khi ho da doc, va do la luat giu
 * viec trich duyet su tht khoi nen nen hang doi o nhung cho khac.
 *
 * Cong viec khong tu quyet dinh gi. No goi dung cac ham danhGia() / canHoiLamRo()
 * / hoanTat() ma controller truoc day goi inline.
 */
class XuLyDanhGiaSangLoc implements ShouldQueue
{
    use Queueable;

    /**
     * Chi mot lan thu. Thu lai se goi nha cung cap them lan nua va co the dua cho
     * nguoi benh mot ket luan khac cho cung cac cau tra loi.
     */
    public int $tries = 1;

    /** Cao hon do tre lon nhat da do duoc cua nha cung cap cong nha cung cap du phong. */
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

        // NhatKyKiemToanService doc Auth::id(); khong co dong nay thi worker ghi cac
        // dong kiem toan voi khach null, dut lien ket voi nguoi dang duoc sang loc.
        Auth::onceUsingId((int) $job->ma_nd);

        try {
            $result = $triage->danhGia($phien, HoSoSucKhoe::consentedFor((int) $job->ma_nd));

            if ($triage->canHoiLamRo($phien, $result)) {
                $triage->luuCChamLamRo($phien, $result);
                $jobs->markDone($job, ['next' => 'clarify']);

                return;
            }

            $phien = $triage->hoanTat($phien, $result);

            // Chi session id, khong bao gio la URL: worker khong co request den, nen
            // route() se dung link tu APP_URL va gui trinh duyet den host hoac port
            // sai. Controller moi bien no thanh URL.
            $jobs->markDone($job, [
                'next' => 'result',
                'ma_sl' => $phien->ma_sl,
            ]);

            // Viet lai loi khuyen la cong viec rieng: no chay sau khi ket luan da an
            // dinh va khong duoc giu nguoi benh lai khi ho dang doc.
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
