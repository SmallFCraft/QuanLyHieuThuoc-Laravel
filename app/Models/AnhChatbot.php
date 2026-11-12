<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AnhChatbot extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'chatbot_images';

    protected $primaryKey = 'MaAnh';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'MaKH',
        'session_id',
        'filename',
        'original_name',
        'image_path',
        'file_path',
        'file_size',
        'mime_type',
        'image_type',
        'analysis_result',
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
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'MaKH', 'MaKH');
    }
}
