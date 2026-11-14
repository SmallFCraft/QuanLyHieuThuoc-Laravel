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
     * Hien thi view yeu cau link dat lai mat khau.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Xu ly mot request link dat lai mat khau den.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Se gui link dat lai mat khau cho nguoi dung nay. Sau khi da thu gui
        // link, se xem xet response roi thay thong bao can hien cho nguoi dung.
        // Cuoi cung se tra ra mot response hop le.
        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status == Password::RESET_LINK_SENT
                    ? back()->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
