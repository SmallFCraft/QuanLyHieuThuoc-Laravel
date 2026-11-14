<?php

namespace App\Models;

use App\Services\Triage\BaoCaoSangLocService;
use Illuminate\Database\Eloquent\Model;

class PhienSangLoc extends Model
{
    protected $table = 'phien_sang_locs';

    protected $primaryKey = 'ma_sl';

    protected $fillable = [
        'ma_nd',
        'session_id',
        'collected_data',
        'matched_symptoms',
        'triggered_red_flags',
        'outcome',
        'possible_condition_groups',
        'advice_given',
        'safety_net',
        'suggested_products',
        'current_step',
        'entry_point',
        'follow_up_at',
        'follow_up_responded_at',
        'follow_up_improved',
        'status',
        'pharmacist_id',
        'pharmacist_note',
        'reviewed_at',
        'ai_advice',
        'ai_safety_net',
        'ai_status',
        'ai_briefing',
        'ai_briefing_status',
        'ai_meta',
        'ai_clarify_rounds',
    ];

    protected $casts = [
        'collected_data' => 'array',
        'matched_symptoms' => 'array',
        'triggered_red_flags' => 'array',
        'possible_condition_groups' => 'array',
        'suggested_products' => 'array',
        'current_step' => 'integer',
        'follow_up_at' => 'datetime',
        'follow_up_responded_at' => 'datetime',
        'follow_up_improved' => 'boolean',
        'reviewed_at' => 'datetime',
        'ai_briefing' => 'array',
        'ai_meta' => 'array',
        'ai_clarify_rounds' => 'integer',
    ];

    public const TOTAL_STEPS = 5;

    /**
     * Vong hoi lam ro la mot cuoc doi thaoi co gioi han, khong phai mot lan doan mo:
     * moi vong deu nhin thay cac cau tra loi truoc. Tran la diem dung cung — 3 vong
     * x 3 cau hoi da nhieu hon nhung gi mot duoc si hoi tai quay thuoc, va mot vong
     * lap khong gioi han se de model cho nguoi benh tra loi mai mai.
     */
    public const MAX_CLARIFY_ROUNDS = 3;
    public const MAX_CLARIFY_QUESTIONS_PER_ROUND = 3;

    public function nguoiDung()
    {
        return $this->belongsTo(NguoiDung::class, 'ma_nd', 'ma_nd');
    }

    public function pharmacist()
    {
        return $this->belongsTo(NguoiDung::class, 'pharmacist_id', 'ma_nd');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopePendingPharmacist($query)
    {
        return $query->where('status', 'pending_pharmacist');
    }

    public function scopeDueForFollowUp($query)
    {
        return $query->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', now())
            ->where('status', 'completed');
    }

    public function isEmergency(): bool
    {
        return $this->outcome === 'emergency';
    }

    /**
     * Cac so lieu do luoc dang sau phien nay, dung cho danh sach so duoc phep trong
     * prompt cua briefing. analysisMetrics() cua controller tinh cung mot diem so cho
     * bieu do; ban copy nay giu nhung con so ma model duoc phep dan.
     *
     * Moi gia tri deu doc tu cot da luu vao co so du lieu — khong gi chay lai bo
     * luat, khong gi suy ra tu ket qua cua model.
     *
     * @return array<string, int|string> label => gia tri hien thi; key la tieng Viet
     *                                      vi cac chuoi nay vao prompt cua model lam
     *                                      danh sach so duoc phep.
     */
    public function analysisMetrics(): array
    {
        $rank = array_search((string) $this->outcome, array_reverse(BaoCaoSangLocService::OUTCOMES), true);
        $bandBase = [0, 25, 50, 75][$rank] ?? 0;

        $redFlags = collect($this->triggered_red_flags ?? []);
        $bySeverity = fn (string $severity) => $redFlags
            ->filter(fn ($co) => is_array($co) && ($co['severity'] ?? '') === $severity)
            ->count();

        $duration = (int) ($this->answerFor('duration_days') ?? 0);
        $symptomCount = count(array_filter((array) $this->matched_symptoms));
        $age = data_get($this->ai_meta, 'age');

        $redPoints = min(30, $bySeverity('emergency') * 15 + $bySeverity('urgent') * 8 + $bySeverity('caution') * 3);
        $durationPoints = min(20, max(0, $duration - 1) * 4);
        $symptomPoints = min(15, max(0, $symptomCount - 1) * 5);
        $agePoints = $age !== null && ((int) $age >= 65 || (int) $age <= 2) ? 10 : 0;
        $factorTotal = $redPoints + $durationPoints + $symptomPoints + $agePoints;
        $score = min($bandBase + 24, $bandBase + (int) round($factorTotal / 75 * 24));

        $metrics = [
            'Điểm nguy cơ (thang 0-100, dải của mức này bắt đầu từ '.$bandBase.')' => $score,
            'Cờ đỏ cấp cứu' => $bySeverity('emergency'),
            'Cờ đỏ cần đi khám' => $bySeverity('urgent'),
            'Cờ đỏ thận trọng' => $bySeverity('caution'),
            'Số triệu chứng chính' => $symptomCount,
            'Số ngày đã có triệu chứng' => $duration,
        ];

        if ($age !== null) {
            $metrics['Tuổi người bệnh'] = (int) $age;
        }

        return $metrics;
    }

    public function answerFor(string $key): mixed
    {
        return data_get($this->collected_data, $key);
    }

    /**
     * Cac cau hoi cua vong hoi lam ro hien tai con chua duoc tra loi.
     *
     * @return array<int, string>
     */
    public function pendingClarifyQuestions(): array
    {
        return array_values(array_filter(
            (array) data_get($this->ai_meta, 'clarify_questions', []),
            fn ($cauHoi): bool => is_string($cauHoi) && trim($cauHoi) !== ''
        ));
    }

    public function hasClarifyRoundsLeft(): bool
    {
        return (int) $this->ai_clarify_rounds < self::MAX_CLARIFY_ROUNDS;
    }

    /**
     * Cac cap cau hoi/cau tra loi tu moi vong da hoan thanh, cu nhat truoc. Cap cho
     * prompt cua vong ke tiep de vong hoi lam ro dung tren cau tra loi truoc thay vi
     * doan lai tu dau.
     *
     * @return array<int, array{round: int, question: string, answer: string}>
     */
    public function clarifyHistory(): array
    {
        return array_values(array_filter(
            (array) ($this->collected_data['ai_clarify_answers'] ?? []),
            fn ($cap): bool => is_array($cap) && ($cap['answer'] ?? '') !== ''
        ));
    }
}
