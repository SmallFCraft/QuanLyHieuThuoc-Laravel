<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Xu ly mot request den.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Dat cac header bao mat
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // microphone=(self), khong phai (): danh sach rong chan ca chinh origin nay,
        // nen Web Speech o o tim kiem bi tu choi o tang policy truoc ca khi browser
        // kip hoi nguoi dung. (self) chi cho phep trang cung origin DUOC YEU CAU —
        // browser van hoi va nguoi dung van phai dong y; iframe khac origin van bi chan.
        // camera van dong: anh vao bang <input type="file">, khong bang camera stream.
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(self), camera=()');
        
        // Content Security Policy (chinh sua theo nhu cau ung dung cua ban)
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
