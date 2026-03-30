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
            'user_id' => $success ? Auth::id() : null,
            'action' => $success ? 'login_success' : 'login_failed',
            'model_type' => 'Auth',
            'model_id' => null,
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
            'user_id' => Auth::id(),
            'action' => 'logout',
            'model_type' => 'Auth',
            'model_id' => null,
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
            'user_id' => Auth::id(),
            'action' => 'inventory_adjustment',
            'model_type' => 'Product',
            'model_id' => $productId,
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
            'user_id' => Auth::id(),
            'action' => 'order_status_change',
            'model_type' => 'Order',
            'model_id' => $orderId,
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
        string $modelType,
        ?int $modelId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ) {
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
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
            ->where('model_id', $modelId)
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
