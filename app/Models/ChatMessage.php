<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $primaryKey = 'MaTN';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'MaKH',
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
    public function user()
    {
        return $this->belongsTo(User::class, 'MaKH', 'MaKH');
    }
}
