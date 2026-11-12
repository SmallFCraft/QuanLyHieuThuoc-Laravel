<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NhatKyKiemToan extends Model
{
    const UPDATED_AT = null; // Audit logs are immutable, no updated_at

    protected $table = 'nhat_ky_kiem_toans';

    protected $primaryKey = 'ma_nk';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'ma_nd',
        'action',
        'model_type',
        'MaDoiTuong',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Get the user that performed the action
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    /**
     * Get the auditable model
     */
    public function auditable()
    {
        return $this->morphTo('model', 'model_type', 'MaDoiTuong');
    }
}
