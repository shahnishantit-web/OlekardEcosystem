<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Middleware;

use App\Modules\Profile\Domain\Services\ProfileMetrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records profile.api.latency histogram for all profile API routes.
 * Operation label is passed as a middleware parameter (e.g. profile.latency:profile.api).
 * Mirrors the AuthLatencyMiddleware pattern from E01.
 */
final class ProfileLatencyMiddleware
{
    public function __construct(private readonly ProfileMetrics $metrics) {}

    public function handle(Request $request, Closure $next, string $operation = 'profile.api'): Response
    {
        $startMs = microtime(true) * 1000.0;

        $response = $next($request);

        $this->metrics->apiLatency($operation, microtime(true) * 1000.0 - $startMs);

        return $response;
    }
}
