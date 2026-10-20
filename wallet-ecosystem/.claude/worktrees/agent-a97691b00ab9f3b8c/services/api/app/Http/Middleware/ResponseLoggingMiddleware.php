<?php
declare(strict_types=1);
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
final class ResponseLoggingMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        Log::info('response.sent', [
            'status'         => $response->getStatusCode(),
            'correlation_id' => $request->header('X-Correlation-ID', ''),
        ]);
        return $response;
    }
}
