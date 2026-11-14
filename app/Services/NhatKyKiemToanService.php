<?php

namespace App\Services;

use App\Models\NhatKyKiemToan;
use Illuminate\Support\Facades\Auth;

/**
 * Moi method la instance method co chu y: service duoc inject vao
 * ChatbotService, AiJobService va cac service triage, va mot static method khong the
 * duoc PHPUnit nhan ban (double) — mock static se throw, catch cua caller nuot no,
 * va dong ghi kiam toan cuoi cung khong duoc test nao phu.
 */
class NhatKyKiemToanService
{
    /**
     * Ghi log mot lan dang nhap
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
     * Ghi log su kien dang xuat
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
     * Ghi log mot hanh dong tuy bien
     *
     * @param int|null $userId Quy gan cho cong viec khong chay trong request cua khach.
     *        Trong worker hang doi khong co guard da xac thuc, nen caller nao da biet
     *        chu so huu phai truyen vao hoac dong ban ghi se mat chu the — day la
     *        vet kiam toan nha thuoc, chu khong phai log su kien UX.
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
