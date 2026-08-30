<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\ChatMessage;
use App\Services\AiJobService;
use App\Services\ChatbotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Run one chatbot turn outside the web request.
 *
 * This is where the whole latency fix lives: the AI call can hold a PHP process
 * for AI_TIMEOUT plus the fallback provider, and `artisan serve` has exactly one
 * process, so doing it inline blocks every other page for the duration.
 *
 * The job owns the complete turn — validation, the provider call, and writing the
 * `chat_messages` rows — so the HTTP layer has no branch of its own to keep in
 * sync. On the `sync` queue driver this runs inside dispatch(), which is why a
 * worker-less install and the test suite behave exactly as they did before.
 */
class ProcessChatbotMessage implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt on purpose. AiProviderService already retries across providers,
     * and a queue-level retry would re-send the same message — risking a second
     * assistant reply for one customer turn.
     */
    public int $tries = 1;

    /** Above the worst case of primary + fallback provider timeouts. */
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
        $userId = (int) $job->MaKH;

        try {
            $response = $chatbot->processMessage($message, $userId, (array) ($payload['context'] ?? []));

            // The customer pressed stop while the provider was answering. Storing the
            // turn now would put a reply to a withdrawn question in the transcript, so
            // the answer is dropped and the `cancelled` row is left as it is.
            if ($job->fresh()?->status === 'cancelled') {
                return;
            }

            // A rejected message is not an error: nothing is stored, exactly as
            // before, and the customer sees the reason.
            if (!isset($response['validation_error'])) {
                $this->storeTurn($userId, $sessionId, $message, $response);
            }

            $jobs->markDone($job, $response);
        } catch (\Throwable $exception) {
            if ($job->fresh()?->status === 'cancelled') {
                return;
            }

            // The transcript must still show what the customer asked and that it
            // failed, otherwise a support request is untraceable.
            $this->storeFailure($userId, $sessionId, $message);

            Log::error('Chatbot message processing failed', [
                'MaKH' => $userId,
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
        ChatMessage::create([
            'MaKH' => $userId,
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

        ChatMessage::create([
            'MaKH' => $userId,
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
            ChatMessage::create([
                'MaKH' => $userId,
                'session_id' => $sessionId,
                'role' => 'user',
                'content' => $message,
                'products' => null,
                'metadata' => null,
                'show_disclaimer' => false,
            ]);

            ChatMessage::create([
                'MaKH' => $userId,
                'session_id' => $sessionId,
                'role' => 'system',
                'content' => 'Không thể xử lý tin nhắn do lỗi hệ thống.',
                'products' => null,
                // A reference, not the provider's words: the raw message carries the
                // upstream request id and error envelope, and this row belongs to the
                // customer. Log::error below records the full text against the same uuid.
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
