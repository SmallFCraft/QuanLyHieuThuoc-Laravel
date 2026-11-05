<?php

namespace App\Models;

use App\Services\Triage\TriageReportService;
use Illuminate\Database\Eloquent\Model;

class TriageSession extends Model
{    protected $primaryKey = 'MaSL';

    protected $fillable = [
        'MaKH',
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
     * Follow-up is a bounded conversation, not one blind guess: each round sees the
     * previous answers. The ceiling is a hard stop — 3 rounds of 3 questions is
     * already more than a pharmacist asks at the counter, and an unbounded loop
     * would let the model keep a patient answering forever.
     */
    public const MAX_CLARIFY_ROUNDS = 3;
    public const MAX_CLARIFY_QUESTIONS_PER_ROUND = 3;

    public function user()
    {
        return $this->belongsTo(User::class, 'MaKH', 'MaKH');
    }

    public function pharmacist()
    {
        return $this->belongsTo(User::class, 'pharmacist_id', 'MaKH');
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
     * The measured facts behind this session, for the briefing prompt's number
     * allow-list. The controller's analysisMetrics() computes the same score for the
     * chart; this copy keeps the numbers the model may quote.
     *
     * Every value is read from persisted columns — nothing re-runs the rule engine,
     * nothing is derived from the model's output.
     *
     * @return array<string, int|string> label => display value; keys are Vietnamese
     *                                      because the strings go into the model's
     *                                      prompt as the number allow-list.
     */
    public function analysisMetrics(): array
    {
        $rank = array_search((string) $this->outcome, array_reverse(TriageReportService::OUTCOMES), true);
        $bandBase = [0, 25, 50, 75][$rank] ?? 0;

        $redFlags = collect($this->triggered_red_flags ?? []);
        $bySeverity = fn (string $severity) => $redFlags
            ->filter(fn ($flag) => is_array($flag) && ($flag['severity'] ?? '') === $severity)
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
     * Questions from the current follow-up round that are still unanswered.
     *
     * @return array<int, string>
     */
    public function pendingClarifyQuestions(): array
    {
        return array_values(array_filter(
            (array) data_get($this->ai_meta, 'clarify_questions', []),
            fn ($question): bool => is_string($question) && trim($question) !== ''
        ));
    }

    public function hasClarifyRoundsLeft(): bool
    {
        return (int) $this->ai_clarify_rounds < self::MAX_CLARIFY_ROUNDS;
    }

    /**
     * Question/answer pairs from every completed round, oldest first. Feeds the next
     * round's prompt so a follow-up builds on the answer instead of guessing again.
     *
     * @return array<int, array{round: int, question: string, answer: string}>
     */
    public function clarifyHistory(): array
    {
        return array_values(array_filter(
            (array) ($this->collected_data['ai_clarify_answers'] ?? []),
            fn ($pair): bool => is_array($pair) && ($pair['answer'] ?? '') !== ''
        ));
    }
}
