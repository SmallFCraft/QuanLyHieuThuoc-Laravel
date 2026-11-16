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
     * Các thuộc tính cho phép gán hàng loạt.
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
     * Các thuộc tính nên ép kiểu.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
