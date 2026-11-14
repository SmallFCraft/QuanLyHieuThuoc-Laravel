<?php

namespace App\Services\Triage\Ai;

use App\Models\PhienSangLoc;
use App\Services\AiProviderService;
use App\Services\NhatKyKiemToanService;
use Illuminate\Support\Facades\Log;

/**
 * Khung chung cho cac seam AI sang loc (trich xuat chi tiet, viet lai loi khuyen,
 * soan loi du phong cho duoc si, briefing ket qua).
 *
 * Chi phan dan dan o day — kill switch, goi nha cung cap, giai ma tool-call,
 * nguon goc. Moi lop con giu prompt, tool schema va kiem tra hop le rieng,
 * vi dung la noi ranh gioi an toan cua moi seam.
 */
abstract class SangLocAiService
{
    public function __construct(
        protected NhatKyKiemToanService $nhatKyKiemToanService,
    ) {}

    /** Key `chatbot.ai.triage.*` tat seam nay. */
    abstract protected function configFlag(): string;

    /** Seconds this seam may spend inside one provider call. */
    abstract protected function timeoutSeconds(): int;

    /**
     * Chi doc config, khong co duong tat `runningUnitTests()`: cac co duoc gat off trong
     * phpunit.xml, nen cac suite hien co khong bao gio cham nha cung cap, trong khi cac test AI
     * van bat duoc tinh nang chung ton tai de phu.
     */
    public function daBat(): bool
    {
        return (bool) config('chatbot.ai.triage.'.$this->configFlag(), false);
    }

    /**
     * Giai moi lan goi, khong inject: constructor cua AiProviderService nem khi
     * AI_BASE_URL/API_KEY/MODEL trong, va /health-check phai van chay —
     * gum viec phong do khan cap — ke ca khi khong cau hinh AI nao.
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
     * Doi so tool-call ma model tra ve, hoac null khi no tra loi bang van ban
     * hoac JSON hong.
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
     * Ghi cot rieng cua lop con va entry `ai_meta` cua seam nay trong mot lan save.
     *
     * @param  array<string, mixed>  $cac cot
     * @param  array<string, mixed>|null  $mo ta
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
     * Chi nguon goc va dang — khong bao gio prompt, khong bao gio van ban sinh ra, khong bao gio
     * loi nguoi dung. Tu nuot loi cua chinh no: row kiem toan thieu khong duoc lam
     * mat cau tra loi da tao ra.
     *
     * @param  array<string, mixed>|null  $mo ta
     * @param  array<string, mixed>  $cac gia tri
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
     * Marker markdown duoc bo chu khong bi tu choi: moi noi hien thi render voi
     * {{ }} va white-space: pre-line, nen marker se hien nhu ky tu nghien van ban.
     * SangLocAiVietLaiService chu dich KHONG dung ham nay — o do cau tra loi markdown
     * co nghia model bi dan lo nhiem vu, nen ban viet lai bi bo thay vi giu.
     */
    protected function stripMarkdown(string $text): string
    {
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/mu', '', $text) ?? $text;
        $text = preg_replace('/(\*\*|__|\*|_|`)/u', '', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;

        return trim(preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text);
    }
}