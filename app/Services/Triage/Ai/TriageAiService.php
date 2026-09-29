<?php

namespace App\Services;

use App\Models\TriageSession;
use Illuminate\Support\Facades\Log;

/**
 * Shared scaffolding for the triage AI seams (fact extraction, advice rewrite,
 * pharmacist draft, result briefing).
 *
 * Only the plumbing lives here — kill switch, provider call, tool-call decoding,
 * provenance. Every subclass keeps its own prompt, tool schema and validation,
 * because that is where each seam's safety boundary actually is.
 */
abstract class TriageAiService
{
    public function __construct(
        protected AuditLogService $auditLogService,
    ) {
    }

    /** The `chatbot.ai.triage.*` key that switches this seam off. */
    abstract protected function configFlag(): string;

    /** Seconds this seam may spend inside one provider call. */
    abstract protected function timeoutSeconds(): int;

    /**
     * Config only, no `runningUnitTests()` short-circuit: the flags are pinned off in
     * phpunit.xml, so the existing suites never reach a provider, while the AI tests
     * can still enable the feature they exist to cover.
     */
    public function isEnabled(): bool
    {
        return (bool) config('chatbot.ai.triage.' . $this->configFlag(), false);
    }

    /**
     * Resolved per call, never injected: the AiProviderService constructor throws when
     * AI_BASE_URL/API_KEY/MODEL are blank, and /health-check must keep working —
     * including emergency detection — with no AI configured at all.
     */
    protected function provider(): AiProviderService
    {
        return app(AiProviderService::class);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function callProvider(AiProviderService $provider, array $messages, array $options = []): array
    {
        return $provider->chat($messages, $options + ['timeout' => $this->timeoutSeconds()]);
    }

    /**
     * The tool-call arguments the model returned, or null when it answered in prose
     * or with malformed JSON.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>|null
     */
    protected function decodeToolCall(array $response): ?array
    {
        $arguments = $response['choices'][0]['message']['function_call']['arguments'] ?? null;

        if (!is_string($arguments) || trim($arguments) === '') {
            return null;
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Write the subclass's own columns and this seam's `ai_meta` entry in one save.
     *
     * @param array<string, mixed> $columns
     * @param array<string, mixed>|null $described
     */
    protected function persistWithProvenance(
        TriageSession $session,
        array $columns,
        string $metaKey,
        string $status,
        ?array $described,
        float $durationMs
    ): void {
        $session->forceFill($columns + [
            'ai_meta' => array_merge((array) $session->ai_meta, [
                $metaKey => [
                    'status' => $status,
                    'provider' => $described ?? [],
                    'latency_ms' => $durationMs,
                ],
            ]),
        ])->save();
    }

    /**
     * Provenance and shape only — never the prompt, never generated text, never the
     * customer's own words. Swallows its own failures: a missing audit row must not
     * cost the answer that was already produced.
     *
     * @param array<string, mixed>|null $described
     * @param array<string, mixed> $values
     */
    protected function auditProvenance(
        TriageSession $session,
        string $action,
        ?array $described,
        float $durationMs,
        array $values = []
    ): void {
        try {
            $this->auditLogService->log(
                action: $action,
                modelType: TriageSession::class,
                modelId: $session->MaSL,
                oldValues: null,
                newValues: $values + [
                    'outcome' => $session->outcome,
                    'ai_provider_mode' => $described['mode'] ?? null,
                    'ai_provider_model' => $described['model'] ?? null,
                    'ai_provider_index' => $described['index'] ?? null,
                    'processing_time_ms' => round($durationMs, 1),
                ],
                userId: $session->MaKH
            );
        } catch (\Throwable $exception) {
            Log::warning('Triage AI audit failed', [
                'action' => $action,
                'MaSL' => $session->MaSL,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Markdown markers removed rather than rejected: every display site renders with
     * {{ }} and white-space: pre-line, so markers would show as literal characters.
     * TriageAiAdviceService deliberately does NOT use this — there a markdown answer
     * means the model was steered off the task, and the rewrite is dropped instead.
     */
    protected function stripMarkdown(string $text): string
    {
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $text) ?? $text;
        $text = preg_replace('/(\*\*|__|\*|_|`)/u', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;

        return trim(preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text);
    }
}
