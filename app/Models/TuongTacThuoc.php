<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TuongTacThuoc extends Model
{
    protected $table = 'drug_interactions';

    protected $primaryKey = 'MaTT';

    protected $fillable = [
        'ingredient_a',
        'ingredient_b',
        'severity',
        'description_vi',
        'recommendation_vi',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
