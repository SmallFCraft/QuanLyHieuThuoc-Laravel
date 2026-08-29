<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalTriageRule extends Model
{
    protected $primaryKey = 'MaQT';

    protected $fillable = [
        'MaTC',
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
        return $this->belongsTo(MedicalSymptom::class, 'MaTC', 'MaTC');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * More specific rules carry a higher priority and must win over generic ones.
     */
    public function scopeMostSpecificFirst($query)
    {
        return $query->orderByDesc('priority')->orderBy('MaQT');
    }
}
