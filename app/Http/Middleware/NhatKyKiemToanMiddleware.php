<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\NhatKyKiemToanService;

class NhatKyKiemToanMiddleware
{
    /**
     * Xu ly mot request den.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Ghi nhat ky cac thao tac quan trong (POST, PUT, PATCH, DELETE)
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE']) && auth()->check()) {
            // Chi ghi nhat ky cac route quan tri
            if ($request->is('admin/*')) {
                $this->logRequest($request, $response);
            }
        }
        
        return $response;
    }
    
    /**
     * Ghi nhat ky HTTP request
     */
    protected function logRequest(Request $request, Response $response)
    {
        $action = $request->method() . ' ' . $request->path();
        $modelType = 'HTTP';

        // Trich thong tin model tu route neu co
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
