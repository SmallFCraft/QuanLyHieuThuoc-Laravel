<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatbotImage extends Model
{
    use Auditable, SoftDeletes;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'filename',
        'original_name',
        'file_path',
        'file_size',
        'mime_type',
        'image_type',
        'ocr_text',
        'recognized_products',
        'processed_at'
    ];
    
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recognized_products' => 'array',
            'processed_at' => 'datetime',
            'file_size' => 'integer'
        ];
    }
    
    /**
     * Get the user that owns the chatbot image.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
