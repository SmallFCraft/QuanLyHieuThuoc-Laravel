<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        // Set security headers
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // microphone=(self), không phải (): danh sách rỗng chặn cả chính origin này,
        // nên Web Speech ở ô tìm kiếm bị từ chối ở tầng policy trước cả khi browser
        // kịp hỏi người dùng. (self) chỉ cho phép trang cùng origin ĐƯỢC YÊU CẦU —
        // browser vẫn hỏi và người dùng vẫn phải đồng ý; iframe khác origin vẫn bị chặn.
        // camera vẫn đóng: ảnh vào bằng <input type="file">, không bằng camera stream.
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(self), camera=()');
        
        // Content Security Policy (adjust as needed for your application)
        $response->headers->set('Content-Security-Policy', 
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com https://cdn.datatables.net; " .
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com https://fonts.bunny.net https://cdn.datatables.net; " .
            "font-src 'self' https://fonts.gstatic.com https://fonts.bunny.net https://cdnjs.cloudflare.com https://cdn.jsdelivr.net data:; " .
            "img-src 'self' data: https: blob:; " .
            "connect-src 'self' https://cdn.jsdelivr.net https://cdn.datatables.net;"
        );
        
        return $response;
    }
}
