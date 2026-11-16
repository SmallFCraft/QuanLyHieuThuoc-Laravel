<?php

namespace App\Traits;

use App\Models\NhatKyKiemToan;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    /**
     * Boot trait auditable cho một model.
     */
    public static function bootAuditable()
    {
        static::created(function ($model) {
            $model->auditCreated();
        });

        static::updated(function ($model) {
            $model->auditUpdated();
        });

        static::deleted(function ($model) {
            $model->auditDeleted();
        });
    }

    /**
     * Ghi log việc tạo model
     */
    protected function auditCreated()
    {
        $this->createAuditLog('created', null, $this->getAuditableAttributes());
    }

    /**
     * Ghi log việc cập nhật model
     */
    protected function auditUpdated()
    {
        $changes = $this->getChanges();
        $original = [];
        
        foreach (array_keys($changes) as $key) {
            $original[$key] = $this->getOriginal($key);
        }
        
        if (!empty($changes)) {
            $this->createAuditLog('updated', $original, $changes);
        }
    }

    /**
     * Ghi log việc xóa model
     */
    protected function auditDeleted()
    {
        $this->createAuditLog('deleted', $this->getAuditableAttributes(), null);
    }

    /**
     * Tạo một dòng nhật ký kiểm toán
     */
    protected function createAuditLog(string $action, ?array $oldValues, ?array $newValues)
    {
        NhatKyKiemToan::create([
            'ma_nd' => Auth::id(),
            'action' => $action,
            'model_type' => get_class($this),
            'MaDoiTuong' => $this->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Lấy các attribute có thể audit (loại bỏ timestamp và dữ liệu nhạy cảm)
     */
    protected function getAuditableAttributes(): array
    {
        $attributes = $this->getAttributes();

        // Bỏ các field nhạy cảm và không nên audit
        $exclude = ['password', 'remember_token', 'created_at', 'updated_at', 'deleted_at'];
        
        return array_diff_key($attributes, array_flip($exclude));
    }
}
