<?php

namespace App\Services;

use App\Jobs\XuLyTinNhanChatbot;
use App\Jobs\XuLyVietLaiLoiKhuyen;
use App\Jobs\XuLyTomTatSangLoc;
use App\Jobs\XuLyDanhGiaSangLoc;
use App\Models\AiJob;
use App\Models\PhienSangLoc;
use App\Services\Triage\Ai\SangLocAiVietLaiService;
use App\Services\Triage\Ai\SangLocAiTomTatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Giao cong viec AI cho worker hang doi va bao trang thai tro lai browser.
 *
 * Van de o do la latency, chu khong phai throughput: mot loi goi AI co the giu mot
 * process PHP trong toan bo AI_TIMEOUT (cong nua nha cung cap du phong), va server
 * dev chi chay mot process, nen mot tin nhan chat chan ca site. Dua loi goi ra khoi
 * request giu moi trang responsive.
 *
 * Queue driver quyet dinh hinh dang cua response, chu khong phai class nay: tren `sync`
 * job da chay xong truoc khi dispatch() tra ve, nen caller co the tra loi trong cung
 * request. Do la thu lam test suite va mot install khong co worker hoat dong giong
 * het code dong bo cu.
 */
class AiJobService
{
    public function __construct(
        protected NhatKyKiemToanService $nhatKyKiemToanService,
    ) {}

    /**
     * @param  array<string, mixed>  $context  Ngu canh chat da dung san. Luu nguy ven:
     *                                         no giu cung cac dong tin nhan ma `chat_messages` dang co, va
     *                                         DonDepCongViecAi xoa no sau 24h.
     * @param  bool  $sync  Chay job trong request nay thay vi dua vao hang doi. Dung khi
     *                      browser phat hien khong worker nao nhan job truoc do.
     */
    public function dispatchChat(
        int $userId,
        string $tinNhan,
        array $context,
        string $sessionId,
        bool $sync = false
    ): AiJob {
        $job = AiJob::create([
            'uuid' => (string) Str::uuid(),
            'ma_nd' => $userId,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'queued',
            'payload' => [
                'message' => $tinNhan,
                'context' => $context,
                'session_id' => $sessionId,
            ],
        ]);

        $this->audit($job, 'ai_job_dispatched');

        if ($sync) {
            XuLyTinNhanChatbot::dispatchSync($job->uuid);
        } else {
            XuLyTinNhanChatbot::dispatch($job->uuid);
        }

        return $job->refresh();
    }

    /**
     * Tra lai quyet dinh ket qua cho mot phieu khai bao da hoan thanh.
     *
     * Payload chi la session id: service doc cau tra loi tu dong ban ghi.
     * Benh nhan cho job nay, nen ket luan khong bao gio duoc sua sau khi ho
     * da doc — xem XuLyDanhGiaSangLoc.
     */
    public function dispatchEvaluation(PhienSangLoc $phien): AiJob
    {
        $job = AiJob::create([
            'uuid' => (string) Str::uuid(),
            'ma_nd' => $phien->ma_nd,
            'kind' => AiJob::KIND_TRIAGE_EVALUATION,
            'status' => 'queued',
            'payload' => ['ma_sl' => $phien->ma_sl],
        ]);

        // Dua vao hang doi, khong chay truc tiep: caller quay ve quyet dinh trong request
        // khi polling cho thay khong worker nao bao gio nhan job.
        XuLyDanhGiaSangLoc::dispatch($job->uuid);

        return $job->refresh();
    }

    /**
     * Tra lai viec viet lai loi khuyen cho mot session da duoc dinh loi.
     *
     * Payload chi la session id: khong co van ban tu do cua khach, khong co field ho so.
     * SangLocAiVietLaiService tu doc nhung gi can tu dong ban ghi.
     */
    public function dispatchAdviceRewrite(PhienSangLoc $phien): AiJob
    {
        $job = AiJob::create([
            'uuid' => (string) Str::uuid(),
            'ma_nd' => $phien->ma_nd,
            'kind' => AiJob::KIND_TRIAGE_ADVICE,
            'status' => 'queued',
            'payload' => ['ma_sl' => $phien->ma_sl],
        ]);

        XuLyVietLaiLoiKhuyen::dispatch($job->uuid);

        return $job->refresh();
    }

    /**
     * Chi dua viec viet lai vao hang doi khi session nay thuc su du dieu kien, va
     * khong bao gio de loi hang doi mat di ket qua cua benh nhan.
     */
    public function dispatchAdviceRewriteIfEligible(PhienSangLoc $phien): ?AiJob
    {
        if (! app(SangLocAiVietLaiService::class)->duDieuKien($phien)) {
            return null;
        }

        try {
            return $this->dispatchAdviceRewrite($phien);
        } catch (\Throwable $exception) {
            Log::warning('Triage advice rewrite skipped', [
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Tra lai briefing chi tiet cho trang ket qua cua mot session da dinh loi.
     *
     * Job rieng khoi viec viet lai loi khuyen la co chu y: loi goi nay xin sau
     * section va mot san pham lua chon, nen cham hon nhieu, va hai viec khong duoc
     * chia se mot loi. Payload chi la session id.
     */
    public function dispatchBriefing(PhienSangLoc $phien): AiJob
    {
        $job = AiJob::create([
            'uuid' => (string) Str::uuid(),
            'ma_nd' => $phien->ma_nd,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'queued',
            'payload' => ['ma_sl' => $phien->ma_sl],
        ]);

        XuLyTomTatSangLoc::dispatch($job->uuid);

        return $job->refresh();
    }

    /**
     * Chi dua briefing vao hang doi khi session du dieu kien, va khong bao gio de
     * loi hang doi mat di ket qua cua benh nhan.
     */
    public function dispatchBriefingIfEligible(PhienSangLoc $phien): ?AiJob
    {
        if (! app(SangLocAiTomTatService::class)->duDieuKien($phien)) {
            return null;
        }

        try {
            return $this->dispatchBriefing($phien);
        } catch (\Throwable $exception) {
            Log::warning('Triage briefing skipped', [
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Trang thai job cho browser, gioi han vao chu so huu.
     *
     * uuid la identifier cong khai, khong phai capability token, nen quyen so huu
     * duoc kiem tra lai tai day thay vi tin vao URL.
     *
     * @return array<string, mixed>|null null khi job khong ton tai hoac thuoc ve
     *                                   nguoi khac — ca hai deu tra 404.
     */
    public function poll(string $uuid, int $userId): ?array
    {
        $job = AiJob::where('uuid', $uuid)->where('ma_nd', $userId)->first();

        if ($job === null) {
            return null;
        }

        return [
            'status' => $job->status,
            'result' => $job->status === 'done' ? $job->result : null,
            'error' => $job->status === 'failed' ? ($job->error ?: null) : null,
            // Cho browser dung polling mot worker ma bao gio khong duoc khoi dong. Gi tiep
            // theo la lua chon cua caller: chat thu lai dong bo, nhung trang ket qua triage
            // khong the — briefing cua no mat ~60-90s, xa hon moi web request — nen no
            // thay bang thong bao "lan nay khong kha dung".
            'stranded' => $job->isStranded(),
            'elapsed_ms' => $job->created_at === null
                ? 0
                : (int) round(($job->finished_at ?? now())->diffInMilliseconds($job->created_at, true)),
        ];
    }

    /**
     * Huu tro mot job ma browser da bo cuoc, de dong ban ghi khong nam `queued`
     * mai mai va worker muon khong the dang cau tra loi thu hai cho cung tin nhan.
     */
    public function abandon(AiJob $job, string $reason): void
    {
        $this->retire($job, 'failed', $reason, 'AI job fell back to sync', 'ai_job_sync_fallback');
    }

    /**
     * Huu tro mot luot ma khach da nhan dung.
     *
     * `cancelled`, khong phai `failed`: poll muon khong duoc ve ra mot loi ma khach
     * khong bao gio gay ra, va XuLyTinNhanChatbot doc lai dong ban ghi sau khi nha cung cap
     * tra loi, nen luot bi dung khong bao gio den `chat_messages` — bang chuyen thoai
     * van giu khop voi nhung gi khach thuc su da thay.
     */
    public function cancel(AiJob $job): void
    {
        $this->retire(
            $job,
            'cancelled',
            'customer stopped the request',
            'AI job cancelled by customer',
            'ai_job_cancelled'
        );
    }

    protected function retire(
        AiJob $job,
        string $status,
        string $reason,
        string $logMessage,
        string $auditAction
    ): void {
        if ($job->isFinished()) {
            return;
        }

        // Dong trong hang doi di cung no. Neu de pending, worker khoi dong sau se thay
        // dong ban ghi da xong va tra ve som — vo hai voi chat, hang da tra loi dong bo,
        // nhung no tich luy cac dong chet trong `jobs` mai mai.
        $this->discardQueuedWork($job->uuid);

        $job->forceFill([
            'status' => $status,
            'error' => $reason,
            'finished_at' => now(),
        ])->save();

        Log::warning($logMessage, [
            'uuid' => $job->uuid,
            'kind' => $job->kind,
            'reason' => $reason,
        ]);

        $this->audit($job, $auditAction);
    }

    /**
     * Xoa dong hang doi dang cho mang uuid nay, de AiJob da huu tro va hang doi dong nhat.
     * Chi database driver luu cac dong ma ta cham duoc; tren `sync` theo dinh nghia
     * khong co gi dang cho.
     */
    public function discardQueuedWork(string $uuid): int
    {
        $connection = (string) config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return 0;
        }

        // uuid duoc nhung trong payload job da serialize, va no la duy nhat.
        return DB::table((string) config("queue.connections.{$connection}.table", 'jobs'))
            ->where('payload', 'like', '%'.$uuid.'%')
            ->delete();
    }

    public function markRunning(AiJob $job): void
    {
        $job->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'attempts' => $job->attempts + 1,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markDone(AiJob $job, array $result): void
    {
        $job->forceFill([
            'status' => 'done',
            'result' => $result,
            'finished_at' => now(),
        ])->save();
    }

    public function markFailed(AiJob $job, string $error): void
    {
        $job->forceFill([
            'status' => 'failed',
            // Cat bot: nha cung cap co the tra ve ca mot trang loi HTML.
            'error' => Str::limit($error, 1000),
            'finished_at' => now(),
        ])->save();

        $this->audit($job, 'ai_job_failed');
    }

    /**
     * Chi status, kind va thoi gian — khong bao gio payload hay loi noi cua khach.
     */
    protected function audit(AiJob $job, string $action): void
    {
        try {
            $this->nhatKyKiemToanService->ghi(
                action: $action,
                modelType: AiJob::class,
                modelId: $job->ma_aj,
                oldValues: null,
                newValues: [
                    'ma_nd' => $job->ma_nd,
                    'kind' => $job->kind,
                    'status' => $job->status,
                    'attempts' => $job->attempts,
                ],
                // Viet tu worker hang doi khong kem hon viet tu request, nen chu so huu
                // den tu dong ban ghi job chu khong phai tu guard.
                userId: $job->ma_nd
            );
        } catch (\Throwable $exception) {
            Log::warning('AI job audit failed', [
                'uuid' => $job->uuid,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
