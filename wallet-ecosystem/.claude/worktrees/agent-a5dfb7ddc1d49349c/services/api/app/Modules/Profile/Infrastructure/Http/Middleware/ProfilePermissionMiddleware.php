<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a named permission on admin profile routes.
 *
 * Reads 'identity.permissions' from request attributes (populated by
 * IdentityAuthMiddleware from JWT claims once E04 is live).
 * Fails closed: if the attribute is absent or the permission is not present → 403.
 */
final class ProfilePermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $permissions = $request->attributes->get('identity.permissions');

        if (!is_array($permissions) || !in_array($permission, $permissions, true)) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'    => 'FORBIDDEN',
                    'message' => 'Insufficient permissions.',
                    'trace_id' => $request->header('X-Correlation-ID', ''),
                ],
            ], 403);
        }

        return $next($request);
    }
}
