<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AnhChatbot extends Model
{
    use Auditable, SoftDeletes;

    protected $table = 'anh_chatbots';

    protected $primaryKey = 'ma_anh';

    /**
     * Các thuộc tính cho phép gán hàng loạt.
     *
     * @var array<string>
     */
    protected $fillable = [
        'ma_nd',
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
     * Các thuộc tính nên ép kiểu.
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
     * Người dùng sở hữu ảnh chatbot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }
}
