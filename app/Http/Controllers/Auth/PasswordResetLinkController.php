<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Hiển thị view yêu cầu link đặt lại mật khẩu.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Xử lý một request link đặt lại mật khẩu đến.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Sẽ gửi link đặt lại mật khẩu cho người dùng này. Sau khi đã thử gửi
        // link, sẽ xem xét response rồi thay thông báo cần hiện cho người dùng.
        // Cuối cùng sẽ trả ra một response hợp lệ.
        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status == Password::RESET_LINK_SENT
                    ? back()->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
