<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\TinNhanChat;
use App\Services\AiJobService;
use App\Services\ChatbotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Chạy một lượt chatbot ngoài web request.
 *
 * Toàn bộ bản sửa độ trễ nằm ở đây: lệnh gọi AI có thể giữ một tiến trình PHP trong
 * AI_TIMEOUT cộng nhà cung cấp dự phòng, con `artisan serve` chỉ có đúng một tiến
 * trình, nên chạy inline sẽ chặn mọi trang khác trong suốt thời gian đó.
 *
 * Công việc sở hữu trọn vẹn một lượt — kiểm tra dữ liệu, lệnh gọi nhà cung cấp, và
 * ghi các dòng `chat_messages` — nên tầng HTTP không có nhánh riêng nào phải giữ
 * đồng bộ. Trên queue driver `sync`, việc này chạy bên trong dispatch(), do đó một
 * cấu hình không có worker và bộ test hành xử đúng như trước đây.
 */
class XuLyTinNhanChatbot implements ShouldQueue
{
    use Queueable;

    /**
     * Một lần thử là có chủ ý. AiProviderService đã retry qua các nhà cung cấp,
     * và retry cấp hàng đợi sẽ gửi lại cùng một tin nhắn — nguy cơ một câu trả lời
     * assistant thứ hai cho cùng một lượt khách.
     */
    public int $tries = 1;

    /** Cao hơn trường hợp xấu nhất của timeout nhà cung cấp chính + dự phòng. */
    public int $timeout = 120;

    public function __construct(public string $uuid)
    {
    }

    public function handle(ChatbotService $chatbot, AiJobService $jobs): void
    {
        $job = AiJob::where('uuid', $this->uuid)->first();

        if ($job === null || $job->isFinished()) {
            return;
        }

        $jobs->markRunning($job);

        $payload = $job->payload ?? [];
        $message = (string) ($payload['message'] ?? '');
        $sessionId = (string) ($payload['session_id'] ?? '');
        $userId = (int) $job->ma_nd;

        try {
            $response = $chatbot->processMessage($message, $userId, (array) ($payload['context'] ?? []));

            // Khách nhấn dừng trong lúc nhà cung cấp đang trả lời. Lưu lượt bây giờ sẽ
            // đặt câu trả lời cho một câu hỏi đã rút vào bảng chuyển thoại, nên câu
            // trả lời bị bỏ và dòng `cancelled` được giữ nguyên như vậy.
            if ($job->fresh()?->status === 'cancelled') {
                return;
            }

            // Một tin nhắn bị từ chối không phải lỗi: không lưu gì, giống hết
            // như trước, và khách thấy lý do.
            if (!isset($response['validation_error'])) {
                $this->storeTurn($userId, $sessionId, $message, $response);
            }

            $jobs->markDone($job, $response);
        } catch (\Throwable $exception) {
            if ($job->fresh()?->status === 'cancelled') {
                return;
            }

            // Bảng chuyển thoại vẫn phải hiện những gì khách đã hỏi và việc nó thất
            // bại, nếu không một yêu cầu hỗ trợ sẽ không thể truy vết.
            $this->storeFailure($userId, $sessionId, $message);

            Log::error('Chatbot message processing failed', [
                'ma_nd' => $userId,
                'session_id' => $sessionId,
                'uuid' => $this->uuid,
                'error' => $exception->getMessage(),
            ]);

            $jobs->markFailed($job, $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    protected function storeTurn(int $userId, string $sessionId, string $message, array $response): void
    {
        TinNhanChat::create([
            'ma_nd' => $userId,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => $message,
            'products' => null,
            'metadata' => null,
            'show_disclaimer' => false,
        ]);

        if (!($response['success'] ?? false)) {
            return;
        }

        TinNhanChat::create([
            'ma_nd' => $userId,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => $response['response'] ?? '',
            'products' => $response['products'] ?? null,
            'metadata' => [
                'explanations' => $response['explanations'] ?? [],
                'categories' => $response['categories'] ?? [],
                'disclaimer' => $response['disclaimer'] ?? null,
                'response_type' => $response['response_type'] ?? null,
                'triage_handoff' => $response['triage_handoff'] ?? null,
                'debug' => $response['debug'] ?? null,
            ],
            'show_disclaimer' => $response['show_disclaimer'] ?? false,
        ]);
    }

    protected function storeFailure(int $userId, string $sessionId, string $message): void
    {
        try {
            TinNhanChat::create([
                'ma_nd' => $userId,
                'session_id' => $sessionId,
                'role' => 'user',
                'content' => $message,
                'products' => null,
                'metadata' => null,
                'show_disclaimer' => false,
            ]);

            TinNhanChat::create([
                'ma_nd' => $userId,
                'session_id' => $sessionId,
                'role' => 'system',
                'content' => 'Không thể xử lý tin nhắn do lỗi hệ thống.',
                'products' => null,
                // Một tham chiếu, chứ không phải lời của nhà cung cấp: tin nhắn thô mang
                // request id upstream và error envelope, và dòng này thuộc về
                // khách. Log::error bên dưới ghi lại toàn văn với cùng uuid.
                'metadata' => [
                    'error_code' => 'ai_job_failed',
                    'ai_job_uuid' => $this->uuid,
                ],
                'show_disclaimer' => false,
            ]);
        } catch (\Throwable $writeFailure) {
            Log::error('Chatbot failure transcript could not be written', [
                'uuid' => $this->uuid,
                'error' => $writeFailure->getMessage(),
            ]);
        }
    }
}