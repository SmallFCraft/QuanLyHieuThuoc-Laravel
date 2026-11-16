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
 * Quyết định kết quả của một bộ câu hỏi đã hoàn thành, ngoài web request.
 *
 * Đo trên nhà cung cấp đã cấu hình, một lệnh gọi trích duyệt sự thật mất 11-30s
 * (trung vị ~14s). Với ngân sách đồng bộ 8s cũ thì gần như luôn bị timeout, và
 * timeout không chỉ là chậm — nó im lặng làm mất các sự thật từ AI, nên người bệnh
 * nói "mấy năm nay rồi" bị tính là một ngày, và vòng hỏi làm rõ theo sau cần phải
 * xuất hiện thì không bao giờ xuất hiện vì `canHoiLamRo()` không có kết quả AI để
 * đọc. Wizard trong như đã nhảy từ "vòng 1/3" thẳng đến trang kết quả.
 *
 * Trì hoãn việc này là an toàn — và khác với việc trì hoãn việc viết lại lời khuyên —
 * vì người bệnh ĐỢI nó: trang kết quả không được hiện cho tới khi công việc đã quyết
 * định. Vì vậy kết luận không bao giờ bị sửa lại sau khi họ đã đọc, và đó là luật giữ
 * việc trích duyệt sự thật khỏi chạy nền ở những chỗ khác.
 *
 * Công việc không tự quyết định gì. Nó gọi đúng các hàm danhGia() / canHoiLamRo()
 * / hoanTat() mà controller trước đây gọi inline.
 */
class XuLyDanhGiaSangLoc implements ShouldQueue
{
    use Queueable;

    /**
     * Chỉ một lần thử. Thử lại sẽ gọi nhà cung cấp thêm lần nữa và có thể đưa cho
     * người bệnh một kết luận khác cho cùng các câu trả lời.
     */
    public int $tries = 1;

    /** Cao hơn độ trễ lớn nhất đã đo được của nhà cung cấp cộng nhà cung cấp dự phòng. */
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

        // NhatKyKiemToanService đọc Auth::id(); không có dòng này thì worker ghi các
        // dòng kiểm toán với khách null, đứt liên kết với người đang được sàng lọc.
        Auth::onceUsingId((int) $job->ma_nd);

        try {
            // Worker: không browser nào đang chờ, nên trích xuất sự thật dùng ngân sách
            // riêng cho từng nhà cung cấp thay vì ngân sách chung — phần dư của primary
            // từng ép fallback xuống 1s và mọi lần chuyển dự phòng thành thất bại.
            $result = $triage->danhGia($phien, HoSoSucKhoe::consentedFor((int) $job->ma_nd), trongWorker: true);

            if ($triage->canHoiLamRo($phien, $result)) {
                $triage->luuCChamLamRo($phien, $result);
                $jobs->markDone($job, ['next' => 'clarify']);

                return;
            }

            $phien = $triage->hoanTat($phien, $result);

            // Chỉ session id, không bao giờ là URL: worker không có request đến, nên
            // route() sẽ dựng link từ APP_URL và gửi trình duyệt đến host hoặc port
            // sai. Controller mới biến nó thành URL.
            $jobs->markDone($job, [
                'next' => 'result',
                'ma_sl' => $phien->ma_sl,
            ]);

            // Viết lại lời khuyên là công việc riêng: nó chạy sau khi kết luận đã cố
            // định và không được giữ người bệnh lại khi họ đang đọc.
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
