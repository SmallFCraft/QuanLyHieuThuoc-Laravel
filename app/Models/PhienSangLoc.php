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
     * Vòng hỏi làm rõ là một cuộc đối thoại có giới hạn, không phải một lần đoán mò:
     * mỗi vòng đều nhìn thấy các câu trả lời trước. Trần là điểm dừng cứng — 3 vòng
     * x 3 câu hỏi đã nhiều hơn những gì một dược sĩ hỏi tại quầy thuốc, và một vòng
     * lặp không giới hạn sẽ để model cho người bệnh trả lời mãi mãi.
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
     * Các số liệu đo lường đằng sau phiên này, dùng cho danh sách số được phép trong
     * prompt của briefing. analysisMetrics() của controller tính cùng một điểm số cho
     * biểu đồ; bản copy này giữ những con số mà model được phép dẫn.
     *
     * Mọi giá trị đều đọc từ cột đã lưu vào cơ sở dữ liệu — không gì chạy lại bộ
     * luật, không gì suy ra từ kết quả của model.
     *
     * @return array<string, int|string> label => giá trị hiển thị; key là tiếng Việt
     *                                      vì các chuỗi này vào prompt của model làm
     *                                      danh sách số được phép.
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
     * Các câu hỏi của vòng hỏi làm rõ hiện tại còn chưa được trả lời.
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
     * Các cặp câu hỏi/câu trả lời từ mỗi vòng đã hoàn thành, cũ nhất trước. Cấp cho
     * prompt của vòng kế tiếp để vòng hỏi làm rõ dựa trên câu trả lời trước thay vì
     * đoán lại từ đầu.
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
