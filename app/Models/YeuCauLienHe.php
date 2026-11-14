<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class YeuCauLienHe extends Model
{
    use HasFactory;

    protected $table = 'yeu_cau_lien_hes';

    protected $primaryKey = 'ma_lh';

    /**
     * Cac thuoc tinh cho phep gan hang loat.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'message',
        'status',
    ];

    /**
     * Cac thuoc tinh nen ep kieu.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
