<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuyTacPhanTangYTe extends Model
{
    protected $table = 'quy_tac_phan_tang_y_tes';

    protected $primaryKey = 'ma_qt';

    protected $fillable = [
        'ma_tc',
        'conditions',
        'outcome',
        'advice_vi',
        'safety_net_vi',
        'condition_group_vi',
        'confidence',
        'suggest_categories',
        'priority',
        'reviewed_by',
        'reviewed_at',
        'is_active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'suggest_categories' => 'array',
        'confidence' => 'float',
        'priority' => 'integer',
        'reviewed_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function symptom()
    {
        return $this->belongsTo(TrieuChungYTe::class, 'ma_tc', 'ma_tc');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Luat cu the hon mang do uu tien cao hon va phai thang qua cac luat chung.
     */
    public function scopeMostSpecificFirst($query)
    {
        return $query->orderByDesc('priority')->orderBy('ma_qt');
    }
}
