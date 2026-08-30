<?php

namespace App\Services;

use App\Mail\MedicationReminderMail;
use App\Models\MedicationReminder;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Builds and dispatches medication reminders derived from completed orders.
 *
 * Dosage frequency is parsed from the free-text `products.usage` column written
 * by the pharmacy staff. When the text cannot be parsed the schedule falls back
 * to once a day — under-reminding is safe, guessing a higher frequency is not.
 */
class MedicationReminderService
{
    /** Reminders only fire between these hours, matching MedicationReminder::advanceSchedule(). */
    private const DAY_START_HOUR = 8;
    private const DAY_END_HOUR = 20;

    /** Waking hours available for spacing doses. */
    private const WAKING_HOURS = 12;

    private const MAX_TIMES_PER_DAY = 6;
    private const MAX_DURATION_DAYS = 30;

    /** `medication_reminders.dosage_text` is a VARCHAR; `products.usage` is TEXT. */
    private const MAX_DOSAGE_TEXT = 255;

    /**
     * Create (or refresh) one reminder per distinct product in the order.
     *
     * Quantities are summed per product first: FEFO splits one purchase into one
     * `order_items` row per batch, so iterating rows directly would overwrite the
     * reminder and derive the course length from the last (smallest) batch.
     *
     * Idempotent: keyed on MaDH + MaSP so replaying a status change cannot
     * duplicate a schedule.
     */
    public function createFromOrder(Order $order): int
    {
        $order->loadMissing('items.product');

        $quantities = [];
        $products = [];

        foreach ($order->items as $item) {
            if (!$item->product) {
                continue;
            }

            $productId = (int) $item->MaSP;
            $quantities[$productId] = ($quantities[$productId] ?? 0) + (int) $item->quantity;
            $products[$productId] = $item->product;
        }

        foreach ($quantities as $productId => $quantity) {
            $product = $products[$productId];
            $timesPerDay = $this->parseTimesPerDay($product->usage);

            MedicationReminder::updateOrCreate(
                [
                    'MaDH' => $order->MaDH,
                    'MaSP' => $productId,
                ],
                [
                    'MaKH' => $order->MaKH,
                    'dosage_text' => $this->dosageText($product),
                    'times_per_day' => $timesPerDay,
                    'duration_days' => $this->durationDays($quantity, $timesPerDay),
                    'start_date' => now()->toDateString(),
                    'next_remind_at' => $this->firstRemindAt(),
                    'status' => 'active',
                ]
            );
        }

        return count($quantities);
    }

    /**
     * `products.usage` is free text written through a WYSIWYG editor, so it can be
     * longer than the column and can carry markup. Truncate rather than let a
     * strict-mode insert abort the order transaction.
     */
    private function dosageText(\App\Models\Product $product): string
    {
        $raw = trim(strip_tags((string) ($product->usage ?? '')));
        $text = $raw !== '' ? $raw : (string) $product->name;

        return mb_substr($text, 0, self::MAX_DOSAGE_TEXT);
    }

    /**
     * Doses per day extracted from Vietnamese usage text.
     *
     * Recognised shapes, in priority order:
     *   "x 2-3 lần/ngày"      -> upper bound (3)
     *   "mỗi 4-6 giờ"         -> waking hours / shortest interval (12/4 = 3)
     *   "1 viên/ngày"         -> single daily dose (1)
     * Anything else returns 1 rather than inventing a frequency.
     */
    public function parseTimesPerDay(?string $usage): int
    {
        if ($usage === null || trim($usage) === '') {
            return 1;
        }

        $text = mb_strtolower($usage, 'UTF-8');

        // "x 2-3 lần/ngày", "3 lần/ngày", "2 lần mỗi ngày"
        if (preg_match('/(\d+)\s*(?:-\s*(\d+))?\s*lần\s*(?:\/|mỗi\s*)ngày/u', $text, $m)) {
            $times = (int) ($m[2] ?? $m[1]);

            return $this->clampTimes($times);
        }

        // "mỗi 4-6 giờ" — shortest stated interval spread over waking hours only
        if (preg_match('/mỗi\s*(\d+)\s*(?:-\s*(\d+))?\s*giờ/u', $text, $m)) {
            $intervalHours = max(1, (int) $m[1]);

            return $this->clampTimes((int) floor(self::WAKING_HOURS / $intervalHours));
        }

        // "1 viên/ngày", "15-30ml/ngày" — one dosing event per day
        if (preg_match('/\/\s*ngày/u', $text)) {
            return 1;
        }

        return 1;
    }

    /**
     * Send every due reminder and roll its schedule forward.
     */
    public function dispatchDue(): int
    {
        $sent = 0;

        MedicationReminder::due()
            // withTrashed on the product: a soft-deleted (discontinued) product is
            // still a course the patient must finish, so it must not cancel the schedule.
            ->with(['user', 'product' => fn ($query) => $query->withTrashed()])
            ->chunkById(100, function ($reminders) use (&$sent) {
                foreach ($reminders as $reminder) {
                    if (!$reminder->user || !$reminder->product) {
                        $reminder->update(['status' => 'cancelled', 'next_remind_at' => null]);
                        continue;
                    }

                    try {
                        Mail::to($reminder->user->email)->send(new MedicationReminderMail($reminder));
                        $sent++;
                    } catch (\Throwable $e) {
                        // A broken mailbox must not stall the rest of the batch.
                        Log::warning('Medication reminder mail failed', [
                            'MaNN' => $reminder->MaNN,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $reminder->advanceSchedule();
                }
            }, 'MaNN');

        return $sent;
    }

    /**
     * Stop one specific schedule. Keyed on the row, not the product: the same
     * medicine can be on two courses from two orders, and cancelling one must not
     * silently stop the other.
     */
    public function cancelSchedule(MedicationReminder $reminder): void
    {
        if ($reminder->status !== 'active') {
            return;
        }

        $reminder->update([
            'status' => 'cancelled',
            'next_remind_at' => null,
        ]);
    }

    /**
     * Stop reminding a specific user about a specific product, across every order.
     */
    public function cancelForUser(int $userId, int $MaSP): void
    {
        MedicationReminder::where('MaKH', $userId)
            ->where('MaSP', $MaSP)
            ->where('status', 'active')
            ->update([
                'status' => 'cancelled',
                'next_remind_at' => null,
            ]);
    }

    private function clampTimes(int $times): int
    {
        return max(1, min(self::MAX_TIMES_PER_DAY, $times));
    }

    /**
     * How long the dispensed quantity lasts at the parsed frequency.
     */
    private function durationDays(int $quantity, int $timesPerDay): int
    {
        $days = (int) ceil(max(1, $quantity) / max(1, $timesPerDay));

        return max(1, min(self::MAX_DURATION_DAYS, $days));
    }

    private function firstRemindAt(): \Illuminate\Support\Carbon
    {
        $now = now();

        if ($now->hour < self::DAY_START_HOUR) {
            return $now->copy()->setTime(self::DAY_START_HOUR, 0);
        }

        if ($now->hour >= self::DAY_END_HOUR) {
            return $now->copy()->addDay()->setTime(self::DAY_START_HOUR, 0);
        }

        return $now;
    }
}
