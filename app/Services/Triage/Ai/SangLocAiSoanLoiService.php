<?php

namespace App\Services\Triage\Ai;

use App\Models\PhienSangLoc;
use App\Services\NhatKyKiemToanService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bản trả lời do AI soạn cho hàng đợi xem lại của dược sĩ.
 *
 * Bản soạn không bao giờ được lưu và không bao giờ tự tới khách hàng: controller
 * chỉ điền trước ô trả lời, nên dược sĩ vẫn phải đọc,
 * sửa và gửi dưới tên chính họ. Người đó là người kiểm tra.
 *
 * Dữ liệu hồ sơ sức khoẻ chỉ đến nhà cung cấp qua SangLocAiNguCanhService,
 * hàm gạt nó trên đồng ý AI rõ ràng. Prompt kết quả cố định của controller
 * vẫn là quan quyền an toàn; ngữ cảnh này chỉ là thông tin bổ trợ.
 */
class SangLocAiSoanLoiService extends SangLocAiService
{
    private const MAX_DRAFT_LENGTH = 2000;

    /**
     * Constructor injection giống SangLocAiTomTatService. An toàn ở đây vì cùng
     * lý do như bên đó: không gì giải seam này trước khi có cấu hình bật, và
     * chính SangLocAiNguCanhService không có constructor ném lỗi — AiProviderService,
     * hàm ném khi không có cấu hình AI, được giải mỗi lần gọi và giữ nguyên.
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
     * Có thể chạy trong form POST của dược sĩ, nên giữ ngân sách fail-fast.
     */
    protected function timeoutSeconds(): int
    {
        return (int) config('chatbot.ai.triage.timeout', 30);
    }

    /**
     * Bản trả lời tiếng Việt, hoặc null khi nhà cung cấp không khả dụng hoặc
     * output không qua kiểm tra độ dài.
     *
     * @param  array<int, string>  $cacTenTrieuChung  Tên triệu chứng tiếng Việt, đã được giải
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
            'KHÔNG viết tiền tố dạng [MODE: ...] hay nhãn điều khiển nào ở đầu câu trả lời; bắt đầu ngay bằng nội dung lời nhắn.',
            'Trả về văn bản thuần, không markdown, không JSON, tối đa 250 từ.',
        ]);
    }

    /**
     * Dữ liệu suy từ quy tắc, tên triệu chứng/cờ đỏ, và ngữ cảnh bệnh nhân tuỳ chọn
     * (tuổi, hồ sơ sức khoẻ đồng ý, và lịch sử sàng lọc gần đây trong hàng rào).
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