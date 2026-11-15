<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NhatKyKiemToan extends Model
{
    const UPDATED_AT = null; // Nhật ký kiểm toán không được sửa, không có updated_at

    protected $table = 'nhat_ky_kiem_toans';

    protected $primaryKey = 'ma_nk';

    /**
     * Các thuộc tính cho phép gán hàng loạt.
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
     * Các thuộc tính nên ép kiểu.
     *
     * @var array
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Người dùng đã thực hiện hành động
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    /**
     * Model có thể kiểm toán được ghi nhận
     */
    public function auditable()
    {
        return $this->morphTo('model', 'model_type', 'MaDoiTuong');
    }
}
