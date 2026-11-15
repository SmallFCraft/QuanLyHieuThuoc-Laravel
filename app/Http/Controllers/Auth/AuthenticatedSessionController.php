<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DangNhapRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Hiển thị view đăng nhập.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Xử lý một request xác thực đến.
     */
    public function store(DangNhapRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        
        app(\App\Services\NhatKyKiemToanService::class)->ghiThuDangNhap($request->email, true);

        return redirect()->intended(route('trang-chu', absolute: false));
    }

    /**
     * Huỷ một phiên làm việc đã xác thực.
     */
    public function destroy(Request $request): RedirectResponse
    {
        app(\App\Services\NhatKyKiemToanService::class)->ghiDangXuat();

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
