<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TinNhanChat extends Model
{
    protected $table = 'tin_nhan_chats';

    protected $primaryKey = 'ma_tn';

    /**
     * The attributes that are mass assignable.
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
     * Get the attributes that should be cast.
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
     * Get the user that owns the message.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }
}
