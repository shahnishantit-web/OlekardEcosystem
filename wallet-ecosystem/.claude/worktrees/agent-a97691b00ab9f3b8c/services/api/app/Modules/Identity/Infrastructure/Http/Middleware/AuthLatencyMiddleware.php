<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Identity\Domain\Services\IdentityMetrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records identity.auth.latency histogram for the three approved hot paths:
 *   identity.latency:login         → POST /v1/auth/login
 *   identity.latency:token_refresh → POST /v1/auth/token/refresh
 *   identity.latency:otp_verify    → POST /v1/auth/otp/verify
 *
 * Latency is measured wall-clock time in milliseconds from before $next($request)
 * to after the response is produced (excluding network transfer).
 */
final class AuthLatencyMiddleware
{
    public function __construct(private readonly IdentityMetrics $metrics) {}

    public function handle(Request $request, Closure $next, string $operation = 'unknown'): Response
    {
        $startMs = microtime(true) * 1000.0;

        $response = $next($request);

        $this->metrics->authLatency(microtime(true) * 1000.0 - $startMs, $operation);

        return $response;
    }
}
