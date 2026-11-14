<?php

namespace App\Services\Triage\Ai;

use App\Models\PhienSangLoc;
use App\Services\NhatKyKiemToanService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ban tra loi do AI soan cho hang doi xem lai cua duoc si.
 *
 * Ban soan khong bao gio duoc luu va khong bao gio tu toi khach hang: controller
 * chi dien truoc o tra loi, nen duoc si van phai doc,
 * sua va gui duoi ten chinh ho. Nguoi do la nguoi kiem tra.
 *
 * Du lieu ho so suc khoe chi den nha cung cap qua SangLocAiNguCanhService,
 * ham gat no tren dong y AI ro rang. Prompt ket qua co dinh cua controller
 * van la quan quyen an toan; ngu canh nay chi la thong tin bo tro.
 */
class SangLocAiSoanLoiService extends SangLocAiService
{
    private const MAX_DRAFT_LENGTH = 2000;

    /**
     * Constructor injection giong SangLocAiTomTatService. An toan o day vi cung
     * ly do nhu ben do: khong gi giai seam nay truoc khi co cau hinh bat, va
     * chinh SangLocAiNguCanhService khong co constructor nem loi — AiProviderService,
     * ham nem khi khong co cau hinh AI, duoc giai moi lan goi va giu nguyen.
     */
    public function __construct(
        protected NhatKyKiemToanService $nhatKyKiemToanService,
        protected SangLocAiNguCanhService $contextService,
    ) {
        parent::__construct($nhatKyKiemToanService);
    }

    protected function configFlag(): string
    {
        return 'pharmacist_draft';
    }

    /**
     * Co the chay trong form POST cua duoc si, nen giu ngan sach fail-fast.
     */
    protected function timeoutSeconds(): int
    {
        return (int) config('chatbot.ai.triage.timeout', 30);
    }

    /**
     * Ban tra loi tieng Viet, hoac null khi nha cung cap khong kha dung hoac
     * output khong qua kiem tra do dai.
     *
     * @param  array<int, string>  $cacTenTrieuChung  Ten trieu chung tieng Viet, da duoc giai
     */
    public function soanLoiTraLoi(PhienSangLoc $phien, array $cacTenTrieuChung): ?string
    {
        if (! $this->daBat()) {
            return null;
        }

        $startedAt = microtime(true);

        try {
            $provider = $this->provider();

            $response = $this->callProvider($provider, [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $this->buildPrompt($phien, $cacTenTrieuChung)],
            ]);

            $draft = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
            $draft = $this->stripMarkdown($draft);

            if ($draft === '') {
                return null;
            }

            $draft = Str::limit($draft, self::MAX_DRAFT_LENGTH, '');

            $this->auditProvenance(
                $phien,
                'triage_ai_draft',
                $provider->describeLastProvider(),
                (microtime(true) - $startedAt) * 1000,
                ['ai_status' => 'ok']
            );

            return $draft;
        } catch (\Throwable $exception) {
            Log::warning('Triage AI draft failed', [
                'ma_sl' => $phien->ma_sl,
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
     * Du lieu suy tu quy tac, ten trieu chung/co do, va ngu canh benh nhan tuy chon
     * (tuoi, ho so suc khoe dong y, va lich su sang loc gan day trong hang rao).
     */
    protected function buildPrompt(PhienSangLoc $phien, array $cacTenTrieuChung): string
    {
        $redFlags = collect($phien->triggered_red_flags ?? [])
            ->map(fn ($flag) => is_array($flag) ? ($flag['name_vi'] ?? $flag['code'] ?? null) : $flag)
            ->filter()
            ->implode(', ');
        $context = $this->contextService->dungNguCanh($phien);

        return implode("\n", array_filter([
            $context !== '' ? "Ngữ cảnh bệnh nhân & lịch sử:\n".$context : null,
            'Kết luận của hệ thống: '.($phien->outcome ?? 'chưa có'),
            $cacTenTrieuChung !== [] ? 'Triệu chứng hệ thống khớp: '.implode(', ', $cacTenTrieuChung) : null,
            $redFlags !== '' ? 'Dấu hiệu cảnh báo: '.$redFlags : null,
            $phien->advice_given ? "Lời khuyên hệ thống đã đưa:\n".$phien->advice_given : null,
            $phien->safety_net ? "Ngưỡng cần đi khám:\n".$phien->safety_net : null,
            '',
            'Soạn bản nháp trả lời khách dựa đúng trên các thông tin trên.',
        ]));
    }
}