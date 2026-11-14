<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TinNhanChat extends Model
{
    protected $table = 'tin_nhan_chats';

    protected $primaryKey = 'ma_tn';

    /**
     * Cac thuoc tinh cho phep gan hang loat.
     *
     * @var array<string>
     */
    protected $fillable = [
        'ma_nd',
        'session_id',
        'role',
        'content',
        'products',
        'metadata',
        'show_disclaimer'
    ];

    /**
     * Cac thuoc tinh nen ep kieu.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'products' => 'array',
            'metadata' => 'array',
            'show_disclaimer' => 'boolean'
        ];
    }

    /**
     * Nguoi dung so huu tin nhan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }
}
