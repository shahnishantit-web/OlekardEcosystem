<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces that the authenticated actor's type matches the required type.
 * Requires identity.auth middleware to run first (sets identity.actor_type).
 * Returns 403 AUTHORIZATION_002 on type mismatch.
 * Comparison is case-insensitive.
 */
final class RequireActorTypeMiddleware
{
    public function handle(Request $request, Closure $next, string $actorType): Response
    {
        $requestActorType = (string) $request->attributes->get('identity.actor_type', '');
        $correlationId    = (string) ($request->header('X-Correlation-ID') ?? (string) Str::uuid());

        if (strtoupper($requestActorType) !== strtoupper($actorType)) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'     => 'AUTHORIZATION_002',
                    'message'  => 'Actor type not permitted.',
                    'trace_id' => $correlationId,
                ],
            ], 403, ['X-Correlation-ID' => $correlationId]);
        }

        return $next($request);
    }
}
