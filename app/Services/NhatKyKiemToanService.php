<?php

namespace App\Services;

use App\Models\NhatKyKiemToan;
use Illuminate\Support\Facades\Auth;

/**
 * Mọi method là instance method có chủ ý: service được inject vào
 * ChatbotService, AiJobService và các service triage, và một static method không thể
 * được PHPUnit nhận ban (double) — mock static sẽ throw, catch của caller nuốt nó,
 * và dòng ghi kiểm toán cuối cùng không được test nào phủ.
 */
class NhatKyKiemToanService
{
    /**
     * Ghi log một lần đăng nhập
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
     * Ghi log sự kiện đăng xuất
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
     * Ghi log một hành động tùy biến
     *
     * @param int|null $userId Quy gán cho công việc không chạy trong request của khách.
     *        Trong worker hàng đợi không có guard đã xác thực, nên caller nào đã biết
     *        chủ sở hữu phải truyền vào hoặc dòng bản ghi sẽ mất chủ thể — đây là
     *        vết kiểm toán nhà thuốc, chứ không phải log sự kiện UX.
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
