<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Một dòng của index phiên âm — bảng tra cứu, không phải thực thể nghiệp vụ.
 *
 * Không quan hệ nào: đường nóng chỉ đọc `term` để trả về khách. Không timestamps:
 * mỗi lần dựng là truncate + insert lại, nên created_at không nói lên điều gì.
 */
class VoicePhonetic extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'phonetic_key',
        'block_key',
        'term',
        'term_folded',
        'kind',
        'weight',
    ];

    protected $casts = [
        'weight' => 'integer',
    ];
}
