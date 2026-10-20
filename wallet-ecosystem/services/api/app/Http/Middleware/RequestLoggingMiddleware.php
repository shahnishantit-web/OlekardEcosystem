<?php
declare(strict_types=1);
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
final class RequestLoggingMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('request.received', [
            'method'         => $request->method(),
            'path'           => $request->path(),
            'correlation_id' => $request->header('X-Correlation-ID', ''),
        ]);
        return $next($request);
    }
}
