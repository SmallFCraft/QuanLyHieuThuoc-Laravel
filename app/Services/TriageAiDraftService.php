<?php

namespace App\Services;

use App\Models\TriageSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI-drafted reply for the pharmacist review queue.
 *
 * The draft is never persisted and never reaches the customer on its own: the
 * controller only prefills the reply textarea, so a pharmacist still reads,
 * edits and submits it under their own name. That makes the human the validator.
 *
 * Health profile data is never sent, even though the pharmacist is allowed to
 * read it on screen — allergies/chronic conditions/current medications are
 * `encrypted:array` at rest and the consent text is purpose-limited.
 */
class TriageAiDraftService extends TriageAiService
{
    private const MAX_DRAFT_LENGTH = 2000;

    protected function configFlag(): string
    {
        return 'pharmacist_draft';
    }

    /**
     * May run inside the pharmacist's form POST, so it keeps the fail-fast budget.
     */
    protected function timeoutSeconds(): int
    {
        return (int) config('chatbot.ai.triage.timeout', 30);
    }

    /**
     * A Vietnamese reply draft, or null when the provider is unavailable or the
     * output fails the length check.
     *
     * @param array<int, string> $symptomNames Vietnamese symptom names, already resolved
     */
    public function draftReply(TriageSession $session, array $symptomNames): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $startedAt = microtime(true);

        try {
            $provider = $this->provider();

            $response = $this->callProvider($provider, [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->buildPrompt($session, $symptomNames)],
            ]);

            $draft = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            $draft = $this->stripMarkdown($draft);

            if ($draft === '') {
                return null;
            }

            $draft = Str::limit($draft, self::MAX_DRAFT_LENGTH, '');

            $this->auditProvenance(
                $session,
                'triage_ai_draft',
                $provider->describeLastProvider(),
                (microtime(true) - $startedAt) * 1000,
                ['ai_status' => 'ok']
            );

            return $draft;
        } catch (\Throwable $exception) {
            Log::warning('Triage AI draft failed', [
                'MaSL' => $session->MaSL,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    protected function systemPrompt(): string
    {
        return implode("\n", [
            'Bạn soạn bản nháp câu trả lời cho dược sĩ tại nhà thuốc Việt Nam.',
            'Người đọc cuối là khách hàng, nên viết ngôi "em", gọi khách là "anh/chị".',
            'Chỉ dùng dữ liệu được cung cấp. KHÔNG thêm chẩn đoán, không thêm tên thuốc,',
            'không thay đổi kết luận của hệ thống, không hứa hẹn kết quả điều trị.',
            'Luôn giữ lại ngưỡng cần đi khám nếu dữ liệu có nêu.',
            'Trả về văn bản thuần, không markdown, không JSON, tối đa 250 từ.',
        ]);
    }

    /**
     * Only rule-derived material and symptom/red-flag names. No free text from the
     * customer, no health profile.
     */
    protected function buildPrompt(TriageSession $session, array $symptomNames): string
    {
        $redFlags = collect($session->triggered_red_flags ?? [])
            ->map(fn ($flag) => is_array($flag) ? ($flag['name_vi'] ?? $flag['code'] ?? null) : $flag)
            ->filter()
            ->implode(', ');

        return implode("\n", array_filter([
            'Kết luận của hệ thống: ' . ($session->outcome ?? 'chưa có'),
            $symptomNames !== [] ? 'Triệu chứng hệ thống khớp: ' . implode(', ', $symptomNames) : null,
            $redFlags !== '' ? 'Dấu hiệu cảnh báo: ' . $redFlags : null,
            $session->advice_given ? "Lời khuyên hệ thống đã đưa:\n" . $session->advice_given : null,
            $session->safety_net ? "Ngưỡng cần đi khám:\n" . $session->safety_net : null,
            '',
            'Soạn bản nháp trả lời khách dựa đúng trên các thông tin trên.',
        ]));
    }
}
