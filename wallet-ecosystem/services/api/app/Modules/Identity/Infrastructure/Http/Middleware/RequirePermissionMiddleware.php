<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use App\Modules\Platform\Application\Services\AuthorizationGuard;
use App\Modules\Platform\Domain\ValueObjects\Actor;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a named permission on the authenticated actor.
 * Requires identity.auth middleware to run first (sets identity.user_id and identity.actor_type).
 * Returns 403 AUTHORIZATION_001 when permission is denied.
 */
final class RequirePermissionMiddleware
{
    public function __construct(
        private readonly AuthorizationGuard $guard,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $actorId       = (string) $request->attributes->get('identity.user_id', '');
        $actorType     = (string) $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $correlationId = (string) ($request->header('X-Correlation-ID') ?? $request->attributes->get('identity.correlation_id') ?? (string) Str::uuid());

        $actor = new Actor($actorId, strtolower($actorType));

        try {
            $this->guard->require($actor, $permission, $correlationId);
        } catch (AuthorizationException) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'     => 'AUTHORIZATION_001',
                    'message'  => 'Insufficient permissions.',
                    'trace_id' => $correlationId,
                ],
            ], 403, ['X-Correlation-ID' => $correlationId]);
        }

        return $next($request);
    }
}
