<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\NguoiDung;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Hiển thị view đặt lại mật khẩu.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Xử lý một request mật khẩu mới đến.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Tại đây sẽ thử đặt lại mật khẩu của người dùng. Nếu thành công sẽ
        // cập nhật mật khẩu trên model NguoiDung thật và lưu vào
        // database. Nếu không sẽ phân tích lỗi và trả về response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (NguoiDung $nguoiDung) use ($request) {
                $nguoiDung->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($nguoiDung));
            }
        );

        // Nếu mật khẩu được đặt lại thành công, sẽ redirect người dùng về
        // view trang chủ đã xác thực của app. Nếu có lỗi có thể
        // redirect họ về cho họ đến với thông báo lỗi của họ.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
