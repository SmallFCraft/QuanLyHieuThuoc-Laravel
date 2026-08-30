<?php

namespace App\Http\Controllers;

use App\Models\MedicationReminder;
use App\Models\TriageSession;
use App\Services\MedicationReminderService;
use App\Services\TriageFollowUpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customer-facing side of medication reminders and post-consultation follow-up.
 *
 * Every action is scoped to the authenticated user: reminder ids and triage
 * session ids travel in emails, so ownership must be re-checked server side.
 */
class ReminderController extends Controller
{
    public function __construct(
        protected MedicationReminderService $reminderService,
        protected TriageFollowUpService $followUpService,
    ) {
    }

    public function index(): View
    {
        $reminders = MedicationReminder::with('product')
            ->where('MaKH', (int) auth()->id())
            ->active()
            ->orderBy('next_remind_at')
            ->get();

        return view('reminders.index', [
            'reminders' => $reminders,
        ]);
    }

    public function cancel(Request $request, int $MaNN): RedirectResponse
    {
        $reminder = MedicationReminder::where('MaKH', (int) auth()->id())
            ->where('MaNN', $MaNN)
            ->first();

        if (!$reminder) {
            return redirect()->route('reminders.index')
                ->with('error', __('messages.reminder.not_found'));
        }

        // Cancel this schedule only. The same product can be on two courses from two
        // orders (an original and a refill), and cancelling one must not stop the other.
        $this->reminderService->cancelSchedule($reminder);

        return redirect()->route('reminders.index')
            ->with('success', __('messages.reminder.cancelled'));
    }

    /**
     * Confirmation page for the follow-up link in the email.
     *
     * The link is a signed GET that changes nothing, so mail scanners and link
     * prefetchers cannot answer on the patient's behalf. The answer is the POST below.
     */
    public function followUpConfirm(Request $request, int $MaSL): View|RedirectResponse
    {
        $improved = $request->boolean('improved');

        $session = TriageSession::where('MaKH', (int) auth()->id())
            ->where('MaSL', $MaSL)
            ->first();

        if (!$session) {
            return redirect()->route('health.history')
                ->with('error', __('messages.reminder.response_forbidden'));
        }

        if ($session->follow_up_responded_at !== null) {
            return redirect()->route('health.result', $session->MaSL)
                ->with('success', __('messages.reminder.response_already_answered'));
        }

        return view('reminders.follow-up-confirm', [
            'session' => $session,
            'improved' => $improved,
        ]);
    }

    /**
     * "Đã đỡ" / "Chưa đỡ" answer, submitted from the confirmation page.
     */
    public function followUpResponse(Request $request, int $MaSL): RedirectResponse
    {
        $validated = $request->validate([
            'improved' => ['required', 'boolean'],
        ]);

        $session = TriageSession::where('MaKH', (int) auth()->id())
            ->where('MaSL', $MaSL)
            ->first();

        if (!$session) {
            return redirect()->route('health.history')
                ->with('error', __('messages.reminder.response_forbidden'));
        }

        $result = $this->followUpService->recordResponse($session, (bool) $validated['improved']);

        if ($result['already_answered']) {
            return redirect()->route('health.result', $session->MaSL)
                ->with('success', __('messages.reminder.response_already_answered'));
        }

        // The layout only renders `success` and `error`; the referral advice must
        // be visible, so it uses the `error` channel rather than a silent key.
        return redirect()->route('health.result', $session->MaSL)
            ->with($result['improved'] ? 'success' : 'error', $result['message']);
    }
}
