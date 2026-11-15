<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QuanTriMiddleware
{
    /**
     * Xử lý một request đến.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check() || !auth()->user()->isAdministrator()) {
            abort(403, 'Truy cập không được phép. Chỉ quản trị viên mới có quyền truy cập.');
        }
        
        if (!auth()->user()->isActive()) {
            auth()->logout();
            return redirect()->route('login')->with('error', 'Tài khoản của bạn đã bị vô hiệu hóa.');
        }
        
        return $next($request);
    }
}
