<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationReminder extends Model
{
    protected $primaryKey = 'MaNN';

    protected $fillable = [
        'MaKH',
        'MaDH',
        'MaSP',
        'dosage_text',
        'times_per_day',
        'duration_days',
        'start_date',
        'next_remind_at',
        'follow_up_at',
        'status',
    ];

    protected $casts = [
        'times_per_day' => 'integer',
        'duration_days' => 'integer',
        'start_date' => 'date',
        'next_remind_at' => 'datetime',
        'follow_up_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'MaKH', 'MaKH');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'MaDH', 'MaDH');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'MaSP', 'MaSP');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDue($query)
    {
        return $query->where('status', 'active')
            ->whereNotNull('next_remind_at')
            ->where('next_remind_at', '<=', now());
    }

    /**
     * Reminders are spread evenly across waking hours (08:00–20:00) rather than
     * around the clock, so nobody is woken at 3am for a paracetamol dose.
     *
     * The next slot is measured from now, not from the stored due date: after a
     * scheduler or worker outage the stored date is hours or days stale, and stepping
     * forward from it would send one mail per missed dose in a burst.
     */
    public function advanceSchedule(): void
    {
        $intervalHours = max(1, (int) floor(12 / max(1, $this->times_per_day)));
        $from = max($this->next_remind_at ?? now(), now());
        $next = $from->copy()->addHours($intervalHours);

        if ($next->hour >= 20) {
            $next = $next->copy()->addDay()->setTime(8, 0);
        } elseif ($next->hour < 8) {
            $next = $next->copy()->setTime(8, 0);
        }

        $endsAt = $this->start_date->copy()->addDays($this->duration_days);

        if ($next->greaterThan($endsAt)) {
            $this->status = 'completed';
            $this->next_remind_at = null;
        } else {
            $this->next_remind_at = $next;
        }

        $this->save();
    }
}
