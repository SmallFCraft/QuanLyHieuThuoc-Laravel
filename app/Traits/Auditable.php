<?php

namespace App\Traits;

use App\Models\NhatKyKiemToan;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    /**
     * Boot trait auditable cho mot model.
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
     * Ghi log viec tao model
     */
    protected function auditCreated()
    {
        $this->createAuditLog('created', null, $this->getAuditableAttributes());
    }

    /**
     * Ghi log viec cap nhat model
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
     * Ghi log viec xoa model
     */
    protected function auditDeleted()
    {
        $this->createAuditLog('deleted', $this->getAuditableAttributes(), null);
    }

    /**
     * Tao mot dong nhat ky kiam toan
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
     * Lay cac attribute co the audit (loai bo timestamp va du lieu nhay cam)
     */
    protected function getAuditableAttributes(): array
    {
        $attributes = $this->getAttributes();

        // Bo cac field nhay cam va khong nen audit
        $exclude = ['password', 'remember_token', 'created_at', 'updated_at', 'deleted_at'];
        
        return array_diff_key($attributes, array_flip($exclude));
    }
}
