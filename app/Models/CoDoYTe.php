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
     * Cac dong chatbot co the dung de cat ngang luong binh thuong. Loai tru cac dong
     * bo canh nguoi benh (co thai, tre so sinh, benh man tinh) ma tro ly sang loc
     * van con doc.
     */
    public function scopeChatShortCircuit($query)
    {
        return $query->where('chat_short_circuit', true);
    }

    /**
     * Cap cuu truoc, roi khan cap, roi canh bao — nguoi goi dua vao thu tu nay de
     * dung lai o phien dang nhat trong nhat. Sap xep bang CASE vi FIELD() chi co tren
     * MySQL con bo test chay tren SQLite.
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
