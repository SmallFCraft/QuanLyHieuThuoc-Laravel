<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NhatKyKiemToan extends Model
{
    const UPDATED_AT = null; // Nhat ky kiem toan khong duoc sua, khong co updated_at

    protected $table = 'nhat_ky_kiem_toans';

    protected $primaryKey = 'ma_nk';

    /**
     * Cac thuoc tinh cho phep gan hang loat.
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
     * Cac thuoc tinh nen ep kieu.
     *
     * @var array
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Nguoi dung da thuc hien hanh dong
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    /**
     * Model co the kiem toan duoc ghi nhan
     */
    public function auditable()
    {
        return $this->morphTo('model', 'model_type', 'MaDoiTuong');
    }
}
