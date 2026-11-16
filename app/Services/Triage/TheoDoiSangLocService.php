<?php

namespace App\Services\Triage;

use App\Mail\TheoDoiSauSangLocMail;
use App\Models\TinNhanChat;
use App\Models\PhienSangLoc;
use App\Services\NhatKyKiemToanService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Lưới an toàn: mọi kết quả tự chăm sóc được kiểm tra lại sau vài ngày.
 *
 * Nếu triệu chứng không cải thiện, câu trả lời luôn là "đi khám". Không
 * sản phẩm nào trả về trên nhánh đó — một công cụ sàng lọc bán thêm thuốc
 * cho người không bớt đi là làm hại, chứ không phải chăm sóc.
 */
class TheoDoiSangLocService
{
    public function __construct(
        protected NhatKyKiemToanService $nhatKyKiemToanService,
    ) {}

    /**
     * Hỏi mọi phiên đến hạn triệu chứng có bớt không.
     */
    public function guiDungHan(): int
    {
        $sent = 0;

        PhienSangLoc::dueForFollowUp()
            ->with('nguoiDung')
            ->chunkById(100, function ($cacPhien) use (&$sent) {
                foreach ($cacPhien as $phien) {
                    if ($this->askOne($phien)) {
                        $sent++;
                    }
                }
            }, 'ma_sl');

        return $sent;
    }

    /**
     * Ghi câu trả lời của khách hàng.
     *
     * Idempotent: link hỏi làm đi qua email và có thể được mở hơn
     * một lần (mail scanner và prefetcher mở nó tự động), nên câu trả lời thứ hai
     * trả về câu đầu tiên thay vì ghi lại.
     *
     * @return array{improved: bool, message: string, products: array<int, mixed>, refer_doctor: bool, already_answered: bool}
     */
    public function ghiPhanHoi(PhienSangLoc $phien, bool $improved): array
    {
        if ($phien->follow_up_responded_at !== null) {
            $recorded = (bool) $phien->follow_up_improved;

            return [
                'improved' => $recorded,
                'message' => $recorded
                    ? __('messages.reminder.response_improved')
                    : __('messages.reminder.response_not_improved'),
                'products' => [],
                'refer_doctor' => ! $recorded,
                'already_answered' => true,
            ];
        }

        $message = $improved
            ? __('messages.reminder.response_improved')
            : __('messages.reminder.response_not_improved');

        $attributes = [
            'follow_up_at' => null,
            'follow_up_responded_at' => now(),
            'follow_up_improved' => $improved,
        ];

        // Chỉ kết quả tự chăm sóc được nâng cấp ở đây. Kết quả khẩn cấp hoặc
        // quyết định dược sĩ nằm trên refer_doctor rồi, nên ghi đè sẽ
        // hạ cấp lời khuyên bệnh nhân đã nhận.
        if (! $improved && $phien->outcome === 'self_care') {
            $attributes['outcome'] = 'refer_doctor';
        }

        $phien->update($attributes);

        $this->writeChatMessage($phien, $message, [
            'type' => 'triage_follow_up_response',
            'improved' => $improved,
            'ma_sl' => $phien->ma_sl,
        ]);

        $this->audit(
            $improved ? 'triage_follow_up_improved' : 'triage_follow_up_not_improved',
            $phien,
            [
                'ma_nd' => $phien->ma_nd,
                'improved' => $improved,
                'outcome' => $phien->outcome,
                'products_suggested' => 0,
            ]
        );

        return [
            'improved' => $improved,
            'message' => $message,
            // Rỗng tuyệt đối cả hai nhánh: hỏi làm là chăm sóc, chứ không phải kênh bán hàng.
            'products' => [],
            'refer_doctor' => ! $improved,
            'already_answered' => false,
        ];
    }

    /**
     * @return bool câu hỏi có thực sự được gửi không
     */
    private function askOne(PhienSangLoc $phien): bool
    {
        if ($phien->nguoiDung) {
            try {
                Mail::to($phien->nguoiDung->email)->send(new TheoDoiSauSangLocMail($phien));
            } catch (\Throwable $e) {
                // Giữ follow_up_at để lần tick sau thử lại. Xoá nó tại đây sẽ
                // bỏ lưới an toàn cho trường hợp tự chăm sóc một cách im lặng, đó là
                // cơ chế duy nhất bắt một quyết định sàng lọc sai.
                Log::warning('Triage follow-up mail failed, will retry', [
                    'ma_sl' => $phien->ma_sl,
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        $this->writeChatMessage($phien, __('messages.reminder.follow_up_question'), [
            'type' => 'triage_follow_up_question',
            'ma_sl' => $phien->ma_sl,
        ]);

        // Xoá ngày đến hạn là thứ ngăn cùng câu hỏi bị hỏi
        // thêm lần nữa ở tick scheduler kế tiếp.
        $phien->update(['follow_up_at' => null]);

        $this->audit('triage_follow_up_sent', $phien, [
            'ma_nd' => $phien->ma_nd,
            'outcome' => $phien->outcome,
        ]);

        return true;
    }

    /**
     * Trò chuyện hỏi làm chỉ tồn tại cho phiên đến từ (hoặc có thể chạm đến)
     * một phiên chat; chat_messages.session_id không cho phép null.
     */
    private function writeChatMessage(PhienSangLoc $phien, string $content, array $metadata): void
    {
        if (empty($phien->session_id)) {
            return;
        }

        TinNhanChat::create([
            'ma_nd' => $phien->ma_nd,
            'session_id' => $phien->session_id,
            'role' => 'assistant',
            'content' => $content,
            'products' => [],
            'metadata' => $metadata,
            'show_disclaimer' => true,
        ]);
    }

    private function audit(string $action, PhienSangLoc $phien, array $values): void
    {
        try {
            $this->nhatKyKiemToanService->ghi(
                action: $action,
                modelType: PhienSangLoc::class,
                modelId: $phien->ma_sl,
                oldValues: null,
                newValues: $values,
                userId: $phien->ma_nd
            );
        } catch (\Throwable $e) {
            Log::warning('Triage follow-up audit failed', [
                'ma_sl' => $phien->ma_sl,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}