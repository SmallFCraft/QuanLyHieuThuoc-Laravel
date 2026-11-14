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
     * Hien thi view dat lai mat khau.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Xu ly mot request mat khau moi den.
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

        // Tai day se thu dat lai mat khau cua nguoi dung. Neu thanh cong se
        // cap nhat mat khau tren model NguoiDung that va luu vao
        // database. Neu khong se phan tich loi va tra ve response.
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

        // Neu mat khau duoc dat lai thanh cong, se redirect nguoi dung ve
        // view trang chu da xac thuc cua app. Neu co loi co the
        // redirect ho ve cho ho den voi thong bao loi cua ho.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
