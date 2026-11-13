<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mot dong cua index phien am — bang tra cuu, khong phai thuc the nghiep vu.
 *
 * Khong quan he nao: duong nong chi doc `term` de tra ve khach. Khong timestamps:
 * moi lan dung la truncate + insert lai, nen created_at khong noi len dieu gi.
 */
class PhienAmGiongNoi extends Model
{
    protected $table = 'phien_am_giong_nois';

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
