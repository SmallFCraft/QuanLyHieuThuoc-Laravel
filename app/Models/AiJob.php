<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiJob extends Model
{
    protected $primaryKey = 'MaAJ';

    public const KIND_CHAT = 'chat';
    public const KIND_TRIAGE_EVALUATION = 'triage_eval';
    public const KIND_TRIAGE_ADVICE = 'triage_advice';
    public const KIND_TRIAGE_BRIEFING = 'triage_briefing';

    /**
     * Never picked up by a worker after this long means no worker is running.
     *
     * Measured against pickup, not execution: a worker claims a job within a second
     * and flips it to `running`, and `isStranded()` returns false for anything not
     * `queued`. So a job still queued after 12s is one nobody is coming for — however
     * long the provider call itself then takes.
     */
    public const SYNC_FALLBACK_SECONDS = 12;

    /**
     * A `running` job older than this lost its worker, so it stops counting as proof
     * that one is alive. Matches ProcessChatbotMessage::$timeout.
     */
    public const WORKER_ALIVE_SECONDS = 120;

    protected $fillable = [
        'uuid',
        'MaKH',
        'kind',
        'status',
        'payload',
        'result',
        'error',
        'attempts',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'MaKH', 'MaKH');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed', 'cancelled'], true);
    }

    /**
     * Still queued long enough that no worker can be running. A `running` job is
     * simply a slow AI call and must not be duplicated.
     *
     * The age test alone is not enough: with one worker and a 45s+45s provider budget,
     * a job can legitimately wait behind the one in flight for far longer than
     * SYNC_FALLBACK_SECONDS. So a job queued behind a worker that is demonstrably
     * alive — another job of this customer's is `running` and younger than
     * WORKER_ALIVE_SECONDS — is waiting, not stranded, and running it inline would
     * block the single-process dev server for nothing.
     */
    public function isStranded(): bool
    {
        if ($this->status !== 'queued' || $this->created_at === null) {
            return false;
        }

        if ($this->created_at->gte(now()->subSeconds(self::SYNC_FALLBACK_SECONDS))) {
            return false;
        }

        return !$this->hasLiveWorker();
    }

    /**
     * Evidence that a worker is consuming the queue right now: a job that reached
     * `running` recently. Scoped to this customer, so one stalled tenant cannot
     * keep another's job pinned in "waiting".
     */
    protected function hasLiveWorker(): bool
    {
        return static::query()
            ->where('MaKH', $this->MaKH)
            ->where('status', 'running')
            ->where('started_at', '>=', now()->subSeconds(self::WORKER_ALIVE_SECONDS))
            ->exists();
    }
}
