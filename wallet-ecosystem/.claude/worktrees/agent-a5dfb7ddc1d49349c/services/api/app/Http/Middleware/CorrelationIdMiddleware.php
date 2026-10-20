<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

final class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID') ?: (string) Uuid::uuid7();
        $requestId     = (string) Uuid::uuid7();

        $request->headers->set('X-Correlation-ID', $correlationId);
        $request->headers->set('X-Request-ID', $requestId);

        // Inject into global log context so every log statement in this request
        // automatically includes correlation_id and request_id (FR-084, FR-087).
        Log::withContext([
            'correlation_id' => $correlationId,
            'request_id'     => $requestId,
        ]);

        $response = $next($request);

        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
