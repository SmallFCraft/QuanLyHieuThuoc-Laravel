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
     * Rows the chatbot may use to abort its normal flow. Excludes patient-context
     * rows (pregnancy, infant, chronic disease) which the triage wizard still reads.
     */
    public function scopeChatShortCircuit($query)
    {
        return $query->where('chat_short_circuit', true);
    }

    /**
     * Emergency first, then urgent, then caution — callers rely on this order to
     * stop at the most severe match. Ordered via CASE because FIELD() is MySQL-only
     * and the test suite runs on SQLite.
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
