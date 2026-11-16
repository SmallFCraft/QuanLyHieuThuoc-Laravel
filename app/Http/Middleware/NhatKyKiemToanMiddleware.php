<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\NhatKyKiemToanService;

class NhatKyKiemToanMiddleware
{
    /**
     * Xử lý một request đến.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Ghi nhật ký các thao tác quan trọng (POST, PUT, PATCH, DELETE)
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE']) && auth()->check()) {
            // Chỉ ghi nhật ký các route quản trị
            if ($request->is('admin/*')) {
                $this->logRequest($request, $response);
            }
        }
        
        return $response;
    }
    
    /**
     * Ghi nhật ký HTTP request
     */
    protected function logRequest(Request $request, Response $response)
    {
        $action = $request->method() . ' ' . $request->path();
        $modelType = 'HTTP';

        // Trích thông tin model từ route nếu có
        if ($request->route()) {
            $routeName = $request->route()->getName();
            if ($routeName) {
                $modelType = 'HTTP:' . $routeName;
            }
        }
        
        app(NhatKyKiemToanService::class)->ghi(
            $action,
            $modelType,
            null,
            null,
            [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'status_code' => $response->getStatusCode(),
            ]
        );
    }
}
