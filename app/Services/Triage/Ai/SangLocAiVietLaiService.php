<?php

namespace App\Services\Triage\Ai;

use App\Models\PhienSangLoc;
use App\Services\AiProviderService;
use Illuminate\Support\Facades\Log;

/**
 * Viết lại lời khuyên dược sĩ soạn thành tiếng Việt thân thiện hơn.
 *
 * Bản viết lại được lưu trong cột riêng và render thành một khối riêng có nhãn,
 * và `advice_given` và `safety_net` không bao giờ bị ghi đè, nên bản ghi xác định
 * được phục hồi từ `rule_id`.
 *
 * Kết quả khẩn cấp và cần thiết bị loại trừ: chúng trả về hướng dẫn dược sĩ viết
 * nguyên văn (số 115, dòng "không aspirin khi nghi sốt xuất huyết"),
 * và mỗi từ đều mang trọng số.
 *
 * Không gửi văn bản tự do của khách hàng hay một trường hồ sơ nào, điều đó
 * làm prompt injection vô giải pháp về cấu trúc chứ không chỉ ít khả năng xảy ra.
 */
class SangLocAiVietLaiService extends SangLocAiService
{
    /**
     * Kết quả có lời khuyên phải tới khách hàng đúng như dược sĩ đã viết
     * nó. `pharmacist_review` đủ điều kiện: khách hàng giờ đọc câu trả lời AI trước
     * và chỉ mời người khi muốn, nên văn bản đó là những gì họ thấy.
     */
    private const VERBATIM_OUTCOMES = ['emergency', 'refer_doctor'];

    private const MAX_LENGTH = 4000;

    protected function configFlag(): string
    {
        return 'advice_rewrite';
    }

    /**
     * Ngân sách riêng, chứ không phải fail-fast của wizard: quy trình gọi này luôn chạy trong
     * worker sau khi kết quả chốt, nên câu trả lời chậm vẫn đang chờ.
     */
    protected function timeoutSeconds(): int
    {
        return (int) config('chatbot.ai.triage.advice_timeout', 45);
    }

    /**
     * Lời khuyên của phiên này có được viết lại không.
     *
     * Public để trang kết quả phân biệt "bản viết lại vẫn đang đến" với "sẽ không bao giờ
     * có", thay vì suy lại quy tắc từ enum kết quả.
     */
    public function duDieuKien(PhienSangLoc $phien): bool
    {
        return $this->daBat()
            && ! in_array($phien->outcome, self::VERBATIM_OUTCOMES, true)
            && trim((string) $phien->advice_given) !== '';
    }

    /**
     * Ghi `ai_advice` / `ai_safety_net` / `ai_status` lên phiên.
     *
     * Không bao giờ ném lỗi: thất bại để cột null và trang kết quả render
     * đúng như khi tính năng off.
     */
    public function vietLai(PhienSangLoc $phien): void
    {
        if (! $this->duDieuKien($phien)) {
            return;
        }

        $source = trim((string) $phien->advice_given);

        $startedAt = microtime(true);

        try {
            $provider = $this->provider();

            $response = $this->callProviderInWorker($provider, [
                ['role' => 'system', 'content' => $this->systemPrompt(SangLocAiNguCanhService::honorificFor($phien->nguoiDung?->gioi_tinh))],
                ['role' => 'user', 'content' => $this->buildPrompt($phien)],
            ]);

            $rewrite = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            $sections = $this->splitSections($rewrite);

            $expected = array_filter([$source, trim((string) $phien->safety_net)]);
            $produced = array_filter([$sections['advice'], $sections['safety_net']]);

            if (! $this->passesSafetyChecks($rewrite, $expected, $produced)) {
                $this->record($phien, 'rejected', null, null, $provider, $startedAt);

                return;
            }

            $this->record(
                $phien,
                'ok',
                $sections['advice'],
                $phien->safety_net ? ($sections['safety_net'] ?: null) : null,
                $provider,
                $startedAt
            );
        } catch (\Throwable $exception) {
            Log::warning('Triage AI advice rewrite failed', [
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);

            $phien->forceFill(['ai_status' => 'failed'])->save();
        }
    }

    protected function systemPrompt(string $honorific = 'anh/chị'): string
    {
        return implode("\n", [
            'Bạn diễn giải lại lời khuyên tự chăm sóc do dược sĩ viết, cho nhà thuốc Việt Nam.',
            'Viết ngôi "em", gọi người đọc là "'.$honorific.'", giọng nhẹ nhàng và rõ ràng.',
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
     * Chỉ chuỗi suy từ quy tắc cộng enum kết quả. Không văn bản tự do, không hồ sơ.
     */
    protected function buildPrompt(PhienSangLoc $phien): string
    {
        $lines = [
            'Kết luận hệ thống: '.$this->outcomeLabel($phien->outcome),
            '',
            'LỜI KHUYÊN:',
            (string) $phien->advice_given,
        ];

        if (trim((string) $phien->safety_net) !== '') {
            $lines[] = '';
            $lines[] = 'NGƯỠNG CẦN ĐI KHÁM:';
            $lines[] = (string) $phien->safety_net;
        }

        $lines[] = '';
        $lines[] = 'Diễn giải lại hai phần trên, giữ đúng nhãn và giữ nguyên mọi con số.';

        return implode("\n", $lines);
    }

    /**
     * Enum -> tiếng Việt thuần, nên model biết phong cách chứ không được báo
     * kết quả là có thể đánh giá lại. Tại đúng nhãn trang kết quả đang hiện.
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

        // Mọi thứ trước nhãn LỜI KHUYÊN là phần đầu model ngả lại —
        // thực tế là dòng "Kết luận hệ thống:" từ prompt, nó rơi xuất hiện
        // nguyên văn trên trang kết quả. Neo tại đầu chuỗi không đủ,
        // nên bỏ mọi thứ trước nhãn bất kể nó xuất hiện ở đâu.
        // Offset byte từ PREG_OFFSET_CAPTURE, nên dùng substr: lần cắt chạm đúng
        // biên khớp, nên không ký tự đa byte nào bị cắt đôi.
        if (preg_match('/LỜI KHUYÊN\s*:?/u', $advice, $match, PREG_OFFSET_CAPTURE) === 1) {
            $advice = substr($advice, $match[0][1] + strlen($match[0][0]));
        }

        return [
            'advice' => $this->clean($advice),
            'safety_net' => $this->clean($parts[1] ?? ''),
        ];
    }

    /**
     * @param  array<int, string>  $expected  Chuỗi nguồn mà bản viết lại phải giữ
     * @param  array<int, string>  $produced  Các khối đã viết lại
     */
    protected function passesSafetyChecks(string $rewrite, array $expected, array $produced): bool
    {
        if ($rewrite === '' || $produced === []) {
            return false;
        }

        if (mb_strlen($rewrite) > self::MAX_LENGTH) {
            return false;
        }

        // Câu trả lời JSON có nghĩa model bị dẫn lôi nhiệm vụ.
        if (json_decode($rewrite, true) !== null) {
            return false;
        }

        // Markdown sẽ render thành ký tự nghiền văn bản: mọi nơi hiển thị dùng
        // {{ }} với white-space: pre-line và không có bộ render markdown.
        if (preg_match('/(\*\*|__|`|^\s{0,3}#{1,6}\s|\[[^\]]*\]\([^)]*\))/mu', $rewrite) === 1) {
            return false;
        }

        // Mọi con số nguồn phải sống sót. Bỏ "39" hoặc "3 ngày" một cách im lặng
        // là mất ngưỡng an toàn, đúng là toàn bộ ý nghĩa của khối.
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
        PhienSangLoc $phien,
        string $status,
        ?string $advice,
        ?string $safetyNet,
        AiProviderService $provider,
        float $startedAt
    ): void {
        $described = $provider->describeLastProvider();
        $durationMs = round((microtime(true) - $startedAt) * 1000, 1);

        $this->persistWithProvenance(
            $phien,
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

        // Chỉ trạng thái và nguồn gốc: không bao giờ prompt, không bao giờ bất kỳ bản nào của
        // văn bản lời khuyên.
        $this->auditProvenance($phien, 'triage_ai_advice', $described, $durationMs, [
            'ai_status' => $status,
        ]);
    }
}