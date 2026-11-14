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
 * Chay mot luot chatbot ngoai web request.
 *
 * Toan bo bản sửa do tre nam o day: loi goi AI co the giu mot tien trinh PHP trong
 * AI_TIMEOUT cong nha cung cap du phong, con `artisan serve` chi co dung mot tien
 * trinh, nen chay inline se chan moi trang khac trong suot thoi gian do.
 *
 * Cong viec so huu tron ven mot luot — kiem tra du lieu, loi goi nha cung cap, va
 * ghi cac dong `chat_messages` — nen tang HTTP khong co nhanh rieng nao phai giu
 * dong bo. Tren queue driver `sync`, viec nay chay ben trong dispatch(), do do mot
 * cau hinh khong co worker va bo test hanh xu dung nhu truoc day.
 */
class XuLyTinNhanChatbot implements ShouldQueue
{
    use Queueable;

    /**
     * Mot lan thu la co chu y. AiProviderService da retry qua cac nha cung cap,
     * va retry cap hang doi se gui lai cung mot tin nhan — nguy co mot cau tra loi
     * assistant thu hai cho cung mot luot khach.
     */
    public int $tries = 1;

    /** Cao hon truong hop xau nhat cua timeout nha cung cap chinh + du phong. */
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

            // Khach nhan dung trong luc nha cung cap dang tra loi. Luu luot bay gio se
            // dua cau tra loi cho mot cau hoi da rut vao bang chuyen thoai, nen cau
            // tra loi bi bo va dong `cancelled` duoc giu nguyen nhu vay.
            if ($job->fresh()?->status === 'cancelled') {
                return;
            }

            // Mot tin nhan bi tu choi khong phai loi: khong luu gi, giong het
            // nhu truoc, va khach thay ly do.
            if (!isset($response['validation_error'])) {
                $this->storeTurn($userId, $sessionId, $message, $response);
            }

            $jobs->markDone($job, $response);
        } catch (\Throwable $exception) {
            if ($job->fresh()?->status === 'cancelled') {
                return;
            }

            // Bang chuyen thoai van phai hien nhung gi khach da hoi va viec no that
            // bai, neu khong mot yeu cau ho tro se khong the truy vet.
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
                // Mot tham chieu, chu khong phai loi cua nha cung cap: tin nhan tho mang
                // request id upstream va error envelope, va dong nay thuoc ve
                // khach. Log::error ben duoi ghi lai van ban day so voi cung uuid.
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
