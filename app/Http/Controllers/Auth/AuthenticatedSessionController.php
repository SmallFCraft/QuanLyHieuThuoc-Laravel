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
     * Hien thi view dang nhap.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Xu ly mot request xac thuc den.
     */
    public function store(DangNhapRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        
        app(\App\Services\NhatKyKiemToanService::class)->ghiThuDangNhap($request->email, true);

        return redirect()->intended(route('trang-chu', absolute: false));
    }

    /**
     * Huy mot phien lam viec da xac thuc.
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
