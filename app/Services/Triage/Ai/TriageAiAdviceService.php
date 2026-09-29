<?php

namespace App\Services\Triage\Ai;

use App\Models\TriageSession;
use App\Services\AiProviderService;
use Illuminate\Support\Facades\Log;

/**
 * Rephrase the pharmacist-written advice into friendlier Vietnamese.
 *
 * The rewrite is stored in its own columns and rendered as a separate, labelled
 * block. `advice_given` and `safety_net` are never overwritten, so the
 * deterministic record stays reconstructible from `rule_id`.
 *
 * Emergency and urgent outcomes are excluded: those return pharmacist-written
 * guidance verbatim (the 115 number, the "no aspirin for suspected dengue" line),
 * and every word is load-bearing.
 *
 * Neither the customer's free text nor any health-profile field is sent, which
 * makes prompt injection structurally unreachable here rather than merely unlikely.
 */
class TriageAiAdviceService extends TriageAiService
{
    /**
     * Outcomes whose advice must reach the customer exactly as the pharmacist wrote
     * it. `pharmacist_review` is eligible: the customer now reads the AI answer first
     * and asks for a human only if they want one, so that text is what they see.
     */
    private const VERBATIM_OUTCOMES = ['emergency', 'refer_doctor'];

    private const MAX_LENGTH = 4000;

    protected function configFlag(): string
    {
        return 'advice_rewrite';
    }

    /**
     * Its own budget, not the wizard's fail-fast one: this call always runs in a
     * worker after the outcome is final, so a slow answer is still worth waiting for.
     */
    protected function timeoutSeconds(): int
    {
        return (int) config('chatbot.ai.triage.advice_timeout', 45);
    }

    /**
     * Whether this session's advice may be rephrased at all.
     *
     * Public so the result page can tell "the rewrite is still coming" from "there
     * will never be one", instead of re-deriving the rule from the outcome enum.
     */
    public function isEligible(TriageSession $session): bool
    {
        return $this->isEnabled()
            && ! in_array($session->outcome, self::VERBATIM_OUTCOMES, true)
            && trim((string) $session->advice_given) !== '';
    }

    /**
     * Write `ai_advice` / `ai_safety_net` / `ai_status` on the session.
     *
     * Never throws: a failure leaves the columns null and the result page renders
     * exactly as it does with the feature off.
     */
    public function rewrite(TriageSession $session): void
    {
        if (! $this->isEligible($session)) {
            return;
        }

        $source = trim((string) $session->advice_given);

        $startedAt = microtime(true);

        try {
            $provider = $this->provider();

            $response = $this->callProvider($provider, [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->buildPrompt($session)],
            ]);

            $rewrite = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            $sections = $this->splitSections($rewrite);

            $expected = array_filter([$source, trim((string) $session->safety_net)]);
            $produced = array_filter([$sections['advice'], $sections['safety_net']]);

            if (! $this->passesSafetyChecks($rewrite, $expected, $produced)) {
                $this->record($session, 'rejected', null, null, $provider, $startedAt);

                return;
            }

            $this->record(
                $session,
                'ok',
                $sections['advice'],
                $session->safety_net ? ($sections['safety_net'] ?: null) : null,
                $provider,
                $startedAt
            );
        } catch (\Throwable $exception) {
            Log::warning('Triage AI advice rewrite failed', [
                'MaSL' => $session->MaSL,
                'error' => $exception->getMessage(),
            ]);

            $session->forceFill(['ai_status' => 'failed'])->save();
        }
    }

    protected function systemPrompt(): string
    {
        return implode("\n", [
            'Bạn diễn giải lại lời khuyên tự chăm sóc do dược sĩ viết, cho nhà thuốc Việt Nam.',
            'Viết ngôi "em", gọi người đọc là "anh/chị", giọng nhẹ nhàng và rõ ràng.',
            'TUYỆT ĐỐI KHÔNG thêm lời khuyên mới, không thêm tên thuốc, không thêm liều,',
            'không bỏ bất kỳ nội dung nào, không đổi bất kỳ con số hay ngưỡng nào.',
            'Giữ nguyên mọi con số như "39 độ", "3 ngày", "4–6 giờ".',
            'Trả về văn bản thuần, không markdown, không JSON.',
            'Nếu phần "NGƯỠNG CẦN ĐI KHÁM" có trong dữ liệu, viết lại nó dưới đúng nhãn đó.',
            'Nếu dữ liệu KHÔNG có phần đó, tuyệt đối không nhắc tới nhãn đó và không giải thích',
            'vì sao thiếu — chỉ trả về phần lời khuyên.',
        ]);
    }

    /**
     * Only rule-derived strings plus the outcome enum. No free text, no profile.
     */
    protected function buildPrompt(TriageSession $session): string
    {
        $lines = [
            'Kết luận hệ thống: '.$this->outcomeLabel($session->outcome),
            '',
            'LỜI KHUYÊN:',
            (string) $session->advice_given,
        ];

        if (trim((string) $session->safety_net) !== '') {
            $lines[] = '';
            $lines[] = 'NGƯỠNG CẦN ĐI KHÁM:';
            $lines[] = (string) $session->safety_net;
        }

        $lines[] = '';
        $lines[] = 'Diễn giải lại hai phần trên, giữ đúng nhãn và giữ nguyên mọi con số.';

        return implode("\n", $lines);
    }

    /**
     * Enum -> plain Vietnamese, so the model knows the register without being told
     * the outcome is negotiable. Reuses the label the result page already shows.
     */
    protected function outcomeLabel(?string $outcome): string
    {
        return match ($outcome) {
            'self_care' => 'tự chăm sóc tại nhà.',
            'pharmacist_review' => 'chưa đủ thông tin để chắc chắn, khách có thể nhờ dược sĩ xem lại.',
            default => (string) $outcome,
        };
    }

    /**
     * @return array{advice: string, safety_net: string}
     */
    protected function splitSections(string $rewrite): array
    {
        $parts = preg_split('/NGƯỠNG CẦN ĐI KHÁM\s*:?/u', $rewrite, 2);
        $advice = $parts[0] ?? '';

        // Everything before the LỜI KHUYÊN label is preamble the model echoed back —
        // in practice the "Kết luận hệ thống:" line from the prompt, which then showed
        // up verbatim on the result page. Anchoring at the start of the string was not
        // enough, so drop whatever precedes the label wherever it appears.
        // Byte offsets from PREG_OFFSET_CAPTURE, hence substr: the cut lands on a
        // match boundary, so no multi-byte character is split.
        if (preg_match('/LỜI KHUYÊN\s*:?/u', $advice, $match, PREG_OFFSET_CAPTURE) === 1) {
            $advice = substr($advice, $match[0][1] + strlen($match[0][0]));
        }

        return [
            'advice' => $this->clean($advice),
            'safety_net' => $this->clean($parts[1] ?? ''),
        ];
    }

    /**
     * @param  array<int, string>  $expected  Source strings the rewrite must preserve
     * @param  array<int, string>  $produced  Rewritten sections
     */
    protected function passesSafetyChecks(string $rewrite, array $expected, array $produced): bool
    {
        if ($rewrite === '' || $produced === []) {
            return false;
        }

        if (mb_strlen($rewrite) > self::MAX_LENGTH) {
            return false;
        }

        // A JSON answer means the model was steered off the task.
        if (json_decode($rewrite, true) !== null) {
            return false;
        }

        // Markdown would render as literal characters: every display site uses
        // {{ }} with white-space: pre-line and no markdown renderer.
        if (preg_match('/(\*\*|__|`|^\s{0,3}#{1,6}\s|\[[^\]]*\]\([^)]*\))/mu', $rewrite) === 1) {
            return false;
        }

        // Every number in the source must survive. Dropping "39" or "3 ngày" silently
        // removes the safety threshold, which is the whole point of the block.
        return $this->numbersPreserved(implode(' ', $expected), implode(' ', $produced));
    }

    protected function numbersPreserved(string $source, string $rewrite): bool
    {
        preg_match_all('/\d+/u', $source, $matches);

        foreach (array_unique($matches[0] ?? []) as $number) {
            if (! str_contains($rewrite, (string) $number)) {
                return false;
            }
        }

        return true;
    }

    protected function clean(string $text): string
    {
        return trim(preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text);
    }

    protected function record(
        TriageSession $session,
        string $status,
        ?string $advice,
        ?string $safetyNet,
        AiProviderService $provider,
        float $startedAt
    ): void {
        $described = $provider->describeLastProvider();
        $durationMs = round((microtime(true) - $startedAt) * 1000, 1);

        $this->persistWithProvenance(
            $session,
            [
                'ai_advice' => $advice,
                'ai_safety_net' => $safetyNet,
                'ai_status' => $status,
            ],
            'advice_rewrite',
            $status,
            $described,
            $durationMs
        );

        // Status and provenance only: never the prompt, never either version of the
        // advice text.
        $this->auditProvenance($session, 'triage_ai_advice', $described, $durationMs, [
            'ai_status' => $status,
        ]);
    }
}
