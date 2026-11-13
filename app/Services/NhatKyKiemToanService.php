<?php

namespace App\Services;

use App\Models\NhatKyKiemToan;
use Illuminate\Support\Facades\Auth;

/**
 * Every method is an instance method on purpose: the service is injected into
 * ChatbotService, AiJobService and the triage services, and a static method cannot
 * be doubled by PHPUnit — a mocked static throws, the caller's catch swallows it,
 * and the audit write ends up covered by no test at all.
 */
class NhatKyKiemToanService
{
    /**
     * Log an authentication attempt
     */
    public function ghiThuDangNhap(string $email, bool $thanhCong, ?string $lyDo = null)
    {
        NhatKyKiemToan::create([
            'ma_nd' => $thanhCong ? Auth::id() : null,
            'action' => $thanhCong ? 'login_success' : 'login_failed',
            'model_type' => 'Auth',
            'MaDoiTuong' => null,
            'old_values' => null,
            'new_values' => [
                'email' => $email,
                'success' => $thanhCong,
                'reason' => $lyDo,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Log a logout event
     */
    public function ghiDangXuat()
    {
        NhatKyKiemToan::create([
            'ma_nd' => Auth::id(),
            'action' => 'logout',
            'model_type' => 'Auth',
            'MaDoiTuong' => null,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Log a custom action
     *
     * @param int|null $userId Attribution for work that does not run in the customer's
     *        request. Inside a queue worker there is no authenticated guard, so a
     *        caller that already knows the owner must pass it or the row loses its
     *        subject — this is a pharmacy audit trail, not a UX event log.
     */
    public function ghi(
        string $action,
        ?string $modelType = null,
        ?int $modelId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ) {
        NhatKyKiemToan::create([
            'ma_nd' => $userId ?? Auth::id(),
            'action' => $action,
            'model_type' => $modelType ?? 'System',
            'MaDoiTuong' => $modelId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
