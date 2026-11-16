<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoDoYTe extends Model
{
    protected $table = 'co_do_y_tes';

    protected $primaryKey = 'ma_cb';

    protected $fillable = [
        'code',
        'name_vi',
        'keywords',
        'severity',
        'chat_short_circuit',
        'guidance_vi',
        'applies_to_symptom',
        'is_active',
    ];

    protected $casts = [
        'keywords' => 'array',
        'chat_short_circuit' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function symptom()
    {
        return $this->belongsTo(TrieuChungYTe::class, 'applies_to_symptom', 'ma_tc');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Các dòng chatbot có thể dùng để cắt ngang luồng bình thường. Loại trừ các dòng
     * bối cảnh người bệnh (có thai, trẻ sơ sinh, bệnh mạn tính) mà trợ lý sàng lọc
     * vẫn còn đọc.
     */
    public function scopeChatShortCircuit($query)
    {
        return $query->where('chat_short_circuit', true);
    }

    /**
     * Cấp cứu trước, rồi khẩn cấp, rồi cảnh báo — người gọi dựa vào thứ tự này để
     * dừng lại ở khớp nghiêm trọng nhất. Sắp xếp bằng CASE vì FIELD() chỉ có trên
     * MySQL còn bộ test chạy trên SQLite.
     */
    public function scopeBySeverity($query)
    {
        return $query->orderByRaw(
            "CASE severity WHEN 'emergency' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END"
        );
    }

    public function isEmergency(): bool
    {
        return $this->severity === 'emergency';
    }
}
