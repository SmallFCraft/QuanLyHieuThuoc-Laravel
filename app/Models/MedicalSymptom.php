<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalSymptom extends Model
{
    protected $primaryKey = 'MaTC';

    protected $fillable = [
        'code',
        'name_vi',
        'aliases',
        'guard_words',
        'body_system',
        'catalog_query',
        'is_active',
    ];

    protected $casts = [
        'aliases' => 'array',
        'guard_words' => 'array',
        'is_active' => 'boolean',
    ];

    public function redFlags()
    {
        return $this->hasMany(MedicalRedFlag::class, 'applies_to_symptom', 'MaTC');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
