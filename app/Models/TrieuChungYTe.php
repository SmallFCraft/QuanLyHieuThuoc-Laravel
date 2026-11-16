<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrieuChungYTe extends Model
{
    protected $table = 'trieu_chung_y_tes';

    protected $primaryKey = 'ma_tc';

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
        return $this->hasMany(CoDoYTe::class, 'applies_to_symptom', 'ma_tc');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
