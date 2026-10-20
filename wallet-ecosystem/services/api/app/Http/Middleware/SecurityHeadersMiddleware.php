<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $maxAge = (int) config('security.hsts_max_age', 31536000);
        $response->headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Content-Security-Policy', (string) config('security.csp_policy', "default-src 'self'; frame-ancestors 'none'"));
        $response->headers->set('Permissions-Policy', (string) config('security.permissions_policy', 'camera=(), microphone=(), payment=()'));

        return $response;
    }
}
