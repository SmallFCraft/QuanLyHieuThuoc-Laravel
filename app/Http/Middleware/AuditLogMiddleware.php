<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\AuditLogService;

class AuditLogMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        // Log critical operations (POST, PUT, PATCH, DELETE)
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE']) && auth()->check()) {
            // Only log admin routes
            if ($request->is('admin/*')) {
                $this->logRequest($request, $response);
            }
        }
        
        return $response;
    }
    
    /**
     * Log the HTTP request
     */
    protected function logRequest(Request $request, Response $response)
    {
        $action = $request->method() . ' ' . $request->path();
        $modelType = 'HTTP';
        
        // Extract model information from route if available
        if ($request->route()) {
            $routeName = $request->route()->getName();
            if ($routeName) {
                $modelType = 'HTTP:' . $routeName;
            }
        }
        
        AuditLogService::log(
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
