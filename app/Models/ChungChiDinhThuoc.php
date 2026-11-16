<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChungChiDinhThuoc extends Model
{
    protected $table = 'chung_chi_dinh_thuocs';

    protected $primaryKey = 'ma_ccd';

    protected $fillable = [
        'ingredient',
        'condition_type',
        'condition_value',
        'severity',
        'warning_vi',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isAbsolute(): bool
    {
        return $this->severity === 'absolute';
    }
}
