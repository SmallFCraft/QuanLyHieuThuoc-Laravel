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
 * Phía khách hàng của nhắc uống thuốc và theo dõi sau tư vấn.
 *
 * Mỗi hành động được giới hạn vào người dùng đã xác thực: nhắc uống id và triage
 * session id đi trong email, nên quyền sở hữu phải được kiểm tra lại phía server.
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

        // Chỉ huỷ lịch này. Cùng một sản phẩm có thể nằm trong hai khoá từ hai đơn hàng
        // (đơn gốc và đơn mua thêm), và huỷ một khoá không được đụng khoá kia.
        $this->nhacUongThuocService->huyLichNhac($nhacUong);

        return redirect()->route('nhac-uong.index')
            ->with('success', __('messages.reminder.cancelled'));
    }

    /**
     * Trang xác nhận cho link theo dõi trong email.
     *
     * Link là một GET có chữ ký không thay đổi gì, nên mail scanner và link
     * prefetcher không thể trả lời thay bệnh nhân. Câu trả lời là POST bên dưới.
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
     * Câu trả lời "Đã đỡ" / "Chưa đỡ", gửi từ trang xác nhận.
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

        // Layout chỉ render `success` và `error`; lời khuyên chuyển tuyến phải hiện
        // được, nên nó dùng kênh `error` thay vì một key im lặng.
        return redirect()->route('sang-loc.ket-qua', $phien->ma_sl)
            ->with($result['improved'] ? 'success' : 'error', $result['message']);
    }
}