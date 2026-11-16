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
 * Giao công việc AI cho worker hàng đợi và báo trạng thái trở lại browser.
 *
 * Vấn đề ở đó là latency, chứ không phải throughput: một lời gọi AI có thể giữ một
 * process PHP trong toàn bộ AI_TIMEOUT (cộng nửa nhà cung cấp dự phòng), và server
 * dev chỉ chạy một process, nên một tin nhắn chat chặn cả site. Đưa lời gọi ra khỏi
 * request giữ mỗi trang responsive.
 *
 * Queue driver quyết định hình dạng của response, chứ không phải class này: trên `sync`
 * job đã chạy xong trước khi dispatch() trả về, nên caller có thể trả lời trong cùng
 * request. Đó là thứ làm test suite và một install không có worker hoạt động giống
 * hệt code đồng bộ cũ.
 */
class AiJobService
{
    public function __construct(
        protected NhatKyKiemToanService $nhatKyKiemToanService,
    ) {}

    /**
     * @param  array<string, mixed>  $context  Ngữ cảnh chat đã dựng sẵn. Lưu nguyên vẹn:
     *                                         nó giữ cùng các dòng tin nhắn mà `chat_messages` đang có, và
     *                                         DonDepCongViecAi xoá nó sau 24h.
     * @param  bool  $sync  Chạy job trong request này thay vì đưa vào hàng đợi. Dùng khi
     *                      browser phát hiện không worker nào nhận job trước đó.
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
     * Trả lại quyết định kết quả cho một phiếu khai báo đã hoàn thành.
     *
     * Payload chỉ là session id: service đọc câu trả lời từ dòng bản ghi.
     * Bệnh nhân chờ job này, nên kết luận không bao giờ được sửa sau khi họ
     * đã đọc — xem XuLyDanhGiaSangLoc.
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

        // Đưa vào hàng đợi, không chạy trực tiếp: caller quay về quyết định trong request
        // khi polling cho thấy không worker nào bao giờ nhận job.
        XuLyDanhGiaSangLoc::dispatch($job->uuid);

        return $job->refresh();
    }

    /**
     * Trả lại việc viết lại lời khuyên cho một session đã được định lôi.
     *
     * Payload chỉ là session id: không có văn bản tự do của khách, không có field hồ sơ.
     * SangLocAiVietLaiService tự đọc những gì cần từ dòng bản ghi.
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
     * Chỉ đưa việc viết lại vào hàng đợi khi session này thực sự đủ điều kiện, và
     * không bao giờ để lỗi hàng đợi mất đi kết quả của bệnh nhân.
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
     * Trả lại briefing chi tiết cho trang kết quả của một session đã định lôi.
     *
     * Job riêng khỏi việc viết lại lời khuyên là có chủ ý: lời gọi này xin sáu
     * section và một sản phẩm lựa chọn, nên chậm hơn nhiều, và hai việc không được
     * chia sẻ một lời. Payload chỉ là session id.
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
     * Chỉ đưa briefing vào hàng đợi khi session đủ điều kiện, và không bao giờ để
     * lỗi hàng đợi mất đi kết quả của bệnh nhân.
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
     * Trạng thái job cho browser, giới hạn vào chủ sở hữu.
     *
     * uuid là identifier công khai, không phải capability token, nên quyền sở hữu
     * được kiểm tra lại tại đây thay vì tin vào URL.
     *
     * @return array<string, mixed>|null null khi job không tồn tại hoặc thuộc về
     *                                   người khác — cả hai đều trả 404.
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
            // Cho browser dừng polling một worker mà bao giờ không được khởi động. Gợi ý tiếp
            // theo là lựa chọn của caller: chat thử lại đồng bộ, nhưng trang kết quả triage
            // không thể — briefing của nó mất ~60-90s, xa hơn mọi web request — nên nó
            // thay bằng thông báo "lần này không khả dụng".
            'stranded' => $job->isStranded(),
            'elapsed_ms' => $job->created_at === null
                ? 0
                : (int) round(($job->finished_at ?? now())->diffInMilliseconds($job->created_at, true)),
        ];
    }

    /**
     * Hưu trí một job mà browser đã bỏ cuộc, để dòng bản ghi không nằm `queued`
     * mãi mãi và worker muộn không thể đăng câu trả lời thứ hai cho cùng tin nhắn.
     */
    public function abandon(AiJob $job, string $reason): void
    {
        $this->retire($job, 'failed', $reason, 'AI job fell back to sync', 'ai_job_sync_fallback');
    }

    /**
     * Hưu trí một lượt mà khách đã nhấn dừng.
     *
     * `cancelled`, không phải `failed`: poll muộn không được vẽ ra một lỗi mà khách
     * không bao giờ gây ra, và XuLyTinNhanChatbot đọc lại dòng bản ghi sau khi nhà cung cấp
     * trả lời, nên lượt bị dừng không bao giờ đến `chat_messages` — bảng chuyển thoại
     * vẫn giữ khớp với những gì khách thực sự đã thấy.
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

        // Dòng trong hàng đợi đi cùng nó. Nếu để pending, worker khởi động sau sẽ thấy
        // dòng bản ghi đã xong và trả về sớm — vô hại với chat, hàng đã trả lời đồng bộ,
        // nhưng nó tích luỹ các dòng chết trong `jobs` mãi mãi.
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
     * Xoá dòng hàng đợi đang chờ mang uuid này, để AiJob đã hưu trí và hàng đợi đồng nhất.
     * Chỉ database driver lưu các dòng mà ta chạm được; trên `sync` theo định nghĩa
     * không có gì đang chờ.
     */
    public function discardQueuedWork(string $uuid): int
    {
        $connection = (string) config('queue.default');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return 0;
        }

        // uuid được nhúng trong payload job đã serialize, và nó là duy nhất.
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
            // Cắt bớt: nhà cung cấp có thể trả về cả một trang lỗi HTML.
            'error' => Str::limit($error, 1000),
            'finished_at' => now(),
        ])->save();

        $this->audit($job, 'ai_job_failed');
    }

    /**
     * Chỉ status, kind và thời gian — không bao giờ payload hay lời nói của khách.
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
                // Viết từ worker hàng đợi không kém hơn viết từ request, nên chủ sở hữu
                // đến từ dòng bản ghi job chứ không phải từ guard.
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
