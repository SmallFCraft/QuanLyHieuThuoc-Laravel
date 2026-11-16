<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TinNhanChat extends Model
{
    protected $table = 'tin_nhan_chats';

    protected $primaryKey = 'ma_tn';

    /**
     * Các thuộc tính cho phép gán hàng loạt.
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
     * Các thuộc tính nên ép kiểu.
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
     * Người dùng sở hữu tin nhắn.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }
}
