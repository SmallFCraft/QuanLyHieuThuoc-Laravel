<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    /**
     * Boot the auditable trait for a model.
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
     * Log model creation
     */
    protected function auditCreated()
    {
        $this->createAuditLog('created', null, $this->getAuditableAttributes());
    }

    /**
     * Log model update
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
     * Log model deletion
     */
    protected function auditDeleted()
    {
        $this->createAuditLog('deleted', $this->getAuditableAttributes(), null);
    }

    /**
     * Create an audit log entry
     */
    protected function createAuditLog(string $action, ?array $oldValues, ?array $newValues)
    {
        AuditLog::create([
            'MaKH' => Auth::id(),
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
     * Get auditable attributes (excluding timestamps and sensitive data)
     */
    protected function getAuditableAttributes(): array
    {
        $attributes = $this->getAttributes();
        
        // Remove sensitive and non-auditable fields
        $exclude = ['password', 'remember_token', 'created_at', 'updated_at', 'deleted_at'];
        
        return array_diff_key($attributes, array_flip($exclude));
    }
}
