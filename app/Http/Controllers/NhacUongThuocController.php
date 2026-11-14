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
 * Phia khach hang cua nhac uong thuoc va theo doi sau tu van.
 *
 * Moi hanh dong duoc gioi han vao nguoi dung da xac thuc: nhac uong id va triage
 * session id di trong email, nen quyen so huu phai duoc kiem tra lai phia server.
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

        // Chi huy lich nay. Cung mot san pham co the nam trong hai khoa tu hai don hang
        // (don goc va don mua them), va huy mot khoa khong duoc dung khoa kia.
        $this->nhacUongThuocService->huyLichNhac($nhacUong);

        return redirect()->route('nhac-uong.index')
            ->with('success', __('messages.reminder.cancelled'));
    }

    /**
     * Trang xac nhan cho link theo doi trong email.
     *
     * Link la mot GET co chu ky khong thay doi gi, nen mail scanner va link
     * prefetcher khong the tra loi thay benh nhan. Cau tra loi la POST ben duoi.
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
     * Cau tra loi "Da do" / "Chua do", gui tu trang xac nhan.
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

        // Layout chi render `success` va `error`; loi khuyen chuyen tuyen phai hien
        // duoc, nen no dung kenh `error` thay vi mot key im lang.
        return redirect()->route('sang-loc.ket-qua', $phien->ma_sl)
            ->with($result['improved'] ? 'success' : 'error', $result['message']);
    }
}