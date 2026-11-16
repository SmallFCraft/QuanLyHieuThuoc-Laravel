<?php

namespace App\Services\Triage\Ai;

use App\Models\PhienSangLoc;
use App\Services\AiProviderService;
use App\Services\NhatKyKiemToanService;
use Illuminate\Support\Facades\Log;

/**
 * Khung chung cho các seam AI sàng lọc (trích xuất chi tiết, viết lại lời khuyên,
 * soạn lời dự phòng cho dược sĩ, briefing kết quả).
 *
 * Chỉ phần cơ chế nằm ở đây — kill switch, gọi nhà cung cấp, giải mã tool-call,
 * nguồn gốc. Mỗi lớp con giữ prompt, tool schema và kiểm tra hợp lệ riêng,
 * vì đúng là nơi ranh giới an toàn của mỗi seam.
 */
abstract class SangLocAiService
{
    public function __construct(
        protected NhatKyKiemToanService $nhatKyKiemToanService,
    ) {}

    /** Key `chatbot.ai.triage.*` tắt seam này. */
    abstract protected function configFlag(): string;

    /** Số giây seam này được phép tiêu trong một lần gọi provider. */
    abstract protected function timeoutSeconds(): int;

    /**
     * Chỉ đọc config, không có đường tắt `runningUnitTests()`: các cờ được gạt off trong
     * phpunit.xml, nên các suite hiện có không bao giờ chạm nhà cung cấp, trong khi các test AI
     * vẫn bắt được tính năng chung tồn tại để phủ.
     */
    public function daBat(): bool
    {
        return (bool) config('chatbot.ai.triage.'.$this->configFlag(), false);
    }

    /**
     * Giải mỗi lần gọi, không inject: constructor của AiProviderService ném khi
     * AI_BASE_URL/API_KEY/MODEL trống, và /health-check phải vẫn chạy —
     * gồm việc phát hiện khẩn cấp — kể cả khi không cấu hình AI nào.
     */
    protected function provider(): AiProviderService
    {
        return app(AiProviderService::class);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function callProvider(AiProviderService $provider, array $messages, array $options = []): array
    {
        return $provider->chat($messages, $options + ['timeout' => $this->timeoutSeconds()]);
    }

    /**
     * Cùng timeoutSeconds nhưng bật ngân sách riêng cho từng nhà cung cấp: seam
     * chạy trong worker (`vietLai`, `sinhNoiDung`) không có browser nào đang chờ,
     * nên ngân sách chung ép fallback xuống phần dư của primary — thực tế là 1s —
     * và mọi lần chuyển dự phòng thành thất bại chắc chắn.
     */
    protected function callProviderInWorker(AiProviderService $provider, array $messages, array $options = []): array
    {
        return $this->callProvider($provider, $messages, $options + ['dedicated_budgets' => true]);
    }

    /**
     * Đối số tool-call mà model trả về, hoặc null khi nó trả lời bằng văn bản
     * hoặc JSON hỏng.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    protected function decodeToolCall(array $response): ?array
    {
        $arguments = $response['choices'][0]['message']['function_call']['arguments'] ?? null;

        if (! is_string($arguments) || trim($arguments) === '') {
            return null;
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Ghi cột riêng của lớp con và entry `ai_meta` của seam này trong một lần save.
     *
     * @param  array<string, mixed>  $các cột
     * @param  array<string, mixed>|null  $mô tả
     */
    protected function persistWithProvenance(
        PhienSangLoc $phien,
        array $columns,
        string $metaKey,
        string $status,
        ?array $described,
        float $durationMs
    ): void {
        $phien->forceFill($columns + [
            'ai_meta' => array_merge((array) $phien->ai_meta, [
                $metaKey => [
                    'status' => $status,
                    'provider' => $described ?? [],
                    'latency_ms' => $durationMs,
                ],
            ]),
        ])->save();
    }

    /**
     * Chỉ nguồn gốc và dạng — không bao giờ prompt, không bao giờ văn bản sinh ra, không bao giờ
     * lời người dùng. Tự nuốt lỗi của chính nó: row kiểm toán thiếu không được làm
     * mất câu trả lời đã tạo ra.
     *
     * @param  array<string, mixed>|null  $mô tả
     * @param  array<string, mixed>  $các giá trị
     */
    protected function auditProvenance(
        PhienSangLoc $phien,
        string $action,
        ?array $described,
        float $durationMs,
        array $values = []
    ): void {
        try {
            $this->nhatKyKiemToanService->ghi(
                action: $action,
                modelType: PhienSangLoc::class,
                modelId: $phien->ma_sl,
                oldValues: null,
                newValues: $values + [
                    'outcome' => $phien->outcome,
                    'ai_provider_mode' => $described['mode'] ?? null,
                    'ai_provider_model' => $described['model'] ?? null,
                    'ai_provider_index' => $described['index'] ?? null,
                    'processing_time_ms' => round($durationMs, 1),
                ],
                userId: $phien->ma_nd
            );
        } catch (\Throwable $exception) {
            Log::warning('Triage AI audit failed', [
                'action' => $action,
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Marker markdown được bóc chứ không bị từ chối: mọi nơi hiển thị render với
     * {{ }} và white-space: pre-line, nên marker sẽ hiện như ký tự nghiền văn bản.
     * SangLocAiVietLaiService cố ý KHÔNG dùng hàm này — ở đó câu trả lời markdown
     * có nghĩa model bị dẫn lôi nhiệm vụ, nên bản viết lại bị bỏ thay vì giữ.
     */
    protected function stripMarkdown(string $text): string
    {
        // Một số nhà cung cấp nhả tiền tố chế độ agent ([MODE: EXECUTE]...) trước
        // nội dung thật; đây là rác điều khiển, không phải chữ gửi khách.
        $text = preg_replace('/^\s*\[MODE:\s*[A-Z_]+\]\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $text) ?? $text;
        $text = preg_replace('/(\*\*|__|\*|_|`)/u', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;

        return trim(preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text);
    }
}