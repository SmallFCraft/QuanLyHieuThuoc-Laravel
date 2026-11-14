<?php

namespace App\Services\Triage\Ai;

use App\Models\PhienSangLoc;
use App\Services\AiProviderService;
use Illuminate\Support\Facades\Log;

/**
 * Viet lai loi khuyen duoc si ban thanh tieng Viet than thien hon.
 *
 * Ban viet lai duoc luu trong cot rieng va render thanh mot khoi rieng co nhan,
 * va `advice_given` va `safety_net` khong bao gio bi ghi de, nen ban ghi xac dinh
 * duoc phuc hoi tu `rule_id`.
 *
 * Ket qua khan cap va can thiet bi loai tru: chung tra ve huong dan duoc si viet
 * nguyen van (so 115, dong "khong aspirin khi nghi sot xuat huet"),
 * va moi tu deu mang trong so.
 *
 * Khong gui van ban tu do cua khach hang hay mot truong ho so nao, dieu do
 * lam prompt injection vo giai phap ve cau truc chu khong chi it kha nang xay ra.
 */
class SangLocAiVietLaiService extends SangLocAiService
{
    /**
     * Ket qua co loi khuyen phai toi khach hang dung nhu duoc si da viet
     * No. `pharmacist_review` du dieu kien: khach hang gio doc cau tra loi AI truoc
     * va chi moi nguoi khi muon, nen van ban do la nhung gi ho thay.
     */
    private const VERBATIM_OUTCOMES = ['emergency', 'refer_doctor'];

    private const MAX_LENGTH = 4000;

    protected function configFlag(): string
    {
        return 'advice_rewrite';
    }

    /**
     * Ngan sach rieng, chu khong phai fail-fast cua wizard: qua trinh goi nay luon chay trong
     * worker sau khi ket qua chot, nen cau tra loi cham van dang cho.
     */
    protected function timeoutSeconds(): int
    {
        return (int) config('chatbot.ai.triage.advice_timeout', 45);
    }

    /**
     * Loi khuyen cua phien nay co duoc viet lai khong.
     *
     * Public de trang ket qua phan biet "ban viet lai van dang den" voi "se khong bao gio
     * co", thay vi suy lai quy tac tu enum ket qua.
     */
    public function duDieuKien(PhienSangLoc $phien): bool
    {
        return $this->daBat()
            && ! in_array($phien->outcome, self::VERBATIM_OUTCOMES, true)
            && trim((string) $phien->advice_given) !== '';
    }

    /**
     * Ghi `ai_advice` / `ai_safety_net` / `ai_status` len phien.
     *
     * Khong bao gio nem loi: that bai de cot null va trang ket qua render
     * dung nhu khi tinh nang off.
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

            $response = $this->callProvider($provider, [
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
     * Chi chuoi suy tu quy tac cong enum ket qua. Khong van ban tu do, khong ho so.
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
     * Enum -> tieng Viet thuan, nen model biet phong cach chu khong duoc bao
     * ket qua la co the danh gia lai. Tai dung nhan trang ket qua dang hien.
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

        // Moi thu truoc nhan LOI KHUYEN la phan dau model nga lai —
        // thuc te la dong "Ket luan he thong:" tu prompt, no roi xuat hien
        // nguyen van tren trang ket qua. Neo tai dau chuoi khong du,
        // nen bo moi thu truoc nhan bat ke no xuat hien o dau.
        // Offset byte tu PREG_OFFSET_CAPTURE, nen dung substr: lan cat cham dung
        // bien khop, nen khong ky tu da byte nao bi cat doi.
        if (preg_match('/LỜI KHUYÊN\s*:?/u', $advice, $match, PREG_OFFSET_CAPTURE) === 1) {
            $advice = substr($advice, $match[0][1] + strlen($match[0][0]));
        }

        return [
            'advice' => $this->clean($advice),
            'safety_net' => $this->clean($parts[1] ?? ''),
        ];
    }

    /**
     * @param  array<int, string>  $expected  Chuoi nguoi ma ban viet lai phai giu
     * @param  array<int, string>  $produced  Cac khoi da viet lai
     */
    protected function passesSafetyChecks(string $rewrite, array $expected, array $produced): bool
    {
        if ($rewrite === '' || $produced === []) {
            return false;
        }

        if (mb_strlen($rewrite) > self::MAX_LENGTH) {
            return false;
        }

        // Cau tra loi JSON co nghia model bi dan lo nhiem vu.
        if (json_decode($rewrite, true) !== null) {
            return false;
        }

        // Markdown se render thanh ky tu nghien van ban: moi noi hien thi dung
        // {{ }} voi white-space: pre-line va khong co bo render markdown.
        if (preg_match('/(\*\*|__|`|^\s{0,3}#{1,6}\s|\[[^\]]*\]\([^)]*\))/mu', $rewrite) === 1) {
            return false;
        }

        // Moi con so trong nguoi phai song sot. Bo "39" hoac "3 ngay" mot cach im lang
        // la mat nguong an toan, dung la toan bo y nghia cua khoi.
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

        // Chi trang thai va nguon goc: khong bao gio prompt, khong bao gio bat ky ban nao cua
        // van ban loi khuyen.
        $this->auditProvenance($phien, 'triage_ai_advice', $described, $durationMs, [
            'ai_status' => $status,
        ]);
    }
}