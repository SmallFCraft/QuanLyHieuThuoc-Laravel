<?php

namespace App\Http\Controllers;


use App\Models\NhacUongThuoc;
use App\Models\PhienSangLoc;
use App\Services\NhacUongThuocService;
use App\Services\Triage\TheoDoiSangLocService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customer-facing side of medication reminders and post-consultation follow-up.
 *
 * Every action is scoped to the authenticated user: reminder ids and triage
 * session ids travel in emails, so ownership must be re-checked server side.
 */
class NhacUongThuocController extends Controller
{
    public function __construct(
        protected NhacUongThuocService $nhacUongThuocService,
        protected TheoDoiSangLocService $followUpService,
    ) {
    }

    public function index(): View
    {
        $cacNhacUong = NhacUongThuoc::with('sanPham')
            ->where('ma_nd', (int) auth()->id())
            ->active()
            ->orderBy('next_remind_at')
            ->get();

        return view('reminders.index', [
            'reminders' => $cacNhacUong,
        ]);
    }

    public function cancel(Request $request, int $MaNN): RedirectResponse
    {
        $nhacUong = NhacUongThuoc::where('ma_nd', (int) auth()->id())
            ->where('ma_nn', $MaNN)
            ->first();

        if (!$nhacUong) {
            return redirect()->route('nhac-uong.index')
                ->with('error', __('messages.reminder.not_found'));
        }

        // Cancel this schedule only. The same product can be on two courses from two
        // orders (an original and a refill), and cancelling one must not stop the other.
        $this->nhacUongThuocService->huyLichNhac($nhacUong);

        return redirect()->route('nhac-uong.index')
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

        $phien = PhienSangLoc::where('ma_nd', (int) auth()->id())
            ->where('ma_sl', $MaSL)
            ->first();

        if (!$phien) {
            return redirect()->route('sang-loc.lich-su')
                ->with('error', __('messages.reminder.response_forbidden'));
        }

        if ($phien->follow_up_responded_at !== null) {
            return redirect()->route('sang-loc.ket-qua', $phien->ma_sl)
                ->with('success', __('messages.reminder.response_already_answered'));
        }

        return view('reminders.follow-up-confirm', [
            'session' => $phien,
            'improved' => $improved,
        ]);
    }

    /**
     * "Da do" / "Chua do" answer, submitted from the confirmation page.
     */
    public function followUpResponse(Request $request, int $MaSL): RedirectResponse
    {
        $validated = $request->validate([
            'improved' => ['required', 'boolean'],
        ]);

        $phien = PhienSangLoc::where('ma_nd', (int) auth()->id())
            ->where('ma_sl', $MaSL)
            ->first();

        if (!$phien) {
            return redirect()->route('sang-loc.lich-su')
                ->with('error', __('messages.reminder.response_forbidden'));
        }

        $result = $this->followUpService->ghiPhanHoi($phien, (bool) $validated['improved']);

        if ($result['already_answered']) {
            return redirect()->route('sang-loc.ket-qua', $phien->ma_sl)
                ->with('success', __('messages.reminder.response_already_answered'));
        }

        // The layout only renders `success` and `error`; the referral advice must
        // be visible, so it uses the `error` channel rather than a silent key.
        return redirect()->route('sang-loc.ket-qua', $phien->ma_sl)
            ->with($result['improved'] ? 'success' : 'error', $result['message']);
    }
}
