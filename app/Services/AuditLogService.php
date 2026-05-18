<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class AuditLogService
{
    /**
     * Log an authentication attempt
     */
    public static function logAuthAttempt(string $email, bool $success, ?string $reason = null)
    {
        AuditLog::create([
            'MaKH' => $success ? Auth::id() : null,
            'action' => $success ? 'login_success' : 'login_failed',
            'model_type' => 'Auth',
            'MaDoiTuong' => null,
            'old_values' => null,
            'new_values' => [
                'email' => $email,
                'success' => $success,
                'reason' => $reason,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Log a logout event
     */
    public static function logLogout()
    {
        AuditLog::create([
            'MaKH' => Auth::id(),
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
     * Log an inventory adjustment
     */
    public static function logInventoryAdjustment(
        int $productId,
        int $oldQuantity,
        int $newQuantity,
        string $reason
    ) {
        AuditLog::create([
            'MaKH' => Auth::id(),
            'action' => 'inventory_adjustment',
            'model_type' => 'Product',
            'MaDoiTuong' => $productId,
            'old_values' => ['stock_quantity' => $oldQuantity],
            'new_values' => [
                'stock_quantity' => $newQuantity,
                'reason' => $reason,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Log an order status change
     */
    public static function logOrderStatusChange(
        int $orderId,
        string $oldStatus,
        string $newStatus
    ) {
        AuditLog::create([
            'MaKH' => Auth::id(),
            'action' => 'order_status_change',
            'model_type' => 'Order',
            'MaDoiTuong' => $orderId,
            'old_values' => ['status' => $oldStatus],
            'new_values' => ['status' => $newStatus],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Log a custom action
     */
    public static function log(
        string $action,
        ?string $modelType = null,
        ?int $modelId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ) {
        AuditLog::create([
            'MaKH' => Auth::id(),
            'action' => $action,
            'model_type' => $modelType ?? 'System',
            'MaDoiTuong' => $modelId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Get audit logs for a specific model
     */
    public static function getLogsForModel(string $modelType, int $modelId)
    {
        return AuditLog::where('model_type', $modelType)
            ->where('MaDoiTuong', $modelId)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get recent audit logs
     */
    public static function getRecentLogs(int $limit = 50)
    {
        return AuditLog::with('user')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
