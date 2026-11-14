<?php

namespace App\Services\Triage;

use App\Mail\TheoDoiSauSangLocMail;
use App\Models\TinNhanChat;
use App\Models\PhienSangLoc;
use App\Services\NhatKyKiemToanService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Luoi an toan: moi ket qua tu cham soc duoc kiem tra lai sau vai ngay.
 *
 * Neu trieu chung khong cai thien, cau tra loi luon la "di khoa". Khong
 * san pham nao tra ve tren nhanh do — mot cong cu sang loc ban them thuoc
 * cho nguoi khong bot di la lam hai, chu khong phai cham soc.
 */
class TheoDoiSangLocService
{
    public function __construct(
        protected NhatKyKiemToanService $nhatKyKiemToanService,
    ) {}

    /**
     * Hoi moi phien den han trieu chung co bot khong.
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
     * Ghi cau tra loi cua khach hang.
     *
     * Idempotent: link hoi lam di qua email va co the duoc mo hon
     * mot lan (mail scanner va prefetcher mo no tu dong), nen cau tra loi thu hai
     * tra ve cau dau tien thay vi ghi lai.
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

        // Chi ket qua tu cham soc duoc nang cap o day. Ket qua khan cap hoac
        // quyet dinh duoc si nam tren refer_doctor roi, nen ghi de se
        // ha cap loi khuyen benh nhan da nhan.
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
            // Rong tuyet doi ca hai nhanh: hoi lam la cham soc, chu khong phai kenh ban hang.
            'products' => [],
            'refer_doctor' => ! $improved,
            'already_answered' => false,
        ];
    }

    /**
     * @return bool cau hoi co thuc su duoc gui khong
     */
    private function askOne(PhienSangLoc $phien): bool
    {
        if ($phien->nguoiDung) {
            try {
                Mail::to($phien->nguoiDung->email)->send(new TheoDoiSauSangLocMail($phien));
            } catch (\Throwable $e) {
                // Giu follow_up_at de lan tick sau thu lai. Xoa no tai day se
                // bo lui luoi an toan cho truong hop tu cham soc mot cach im lang, do la
                // co che duy nai bat mot quyet dinh sang loc sai.
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

        // Xoa ngay den han la thu ngan cung cau hoi bi hoi
        // them lan nua o tick scheduler ke tiep.
        $phien->update(['follow_up_at' => null]);

        $this->audit('triage_follow_up_sent', $phien, [
            'ma_nd' => $phien->ma_nd,
            'outcome' => $phien->outcome,
        ]);

        return true;
    }

    /**
     * Tro chuyen hoi lam chi ton tai cho phien den tu (hoac co the cham den)
     * mot phien chat; chat_messages.session_id khong cho phep null.
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