<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only middleware that reads allowed permissions from the identity.permissions
 * request attribute (set by FakeIdentityAuthMiddleware) rather than querying the DB.
 * Bind this over 'identity.require-permission' alias in test setUp.
 */
final class FakeRequirePermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $actorId = (string) $request->attributes->get('identity.user_id', '');

        if ($actorId === '') {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'UNAUTHORIZED', 'message' => 'Authentication required.'],
            ], 401);
        }

        $granted = in_array($permission, (array) $request->attributes->get('identity.permissions', []), true);

        if (! $granted) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'PERMISSION_DENIED', 'message' => 'Insufficient permissions.'],
            ], 403);
        }

        return $next($request);
    }
}
