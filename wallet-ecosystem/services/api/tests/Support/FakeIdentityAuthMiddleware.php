<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only middleware that reads identity claims from test headers instead of validating a real JWT.
 * Bind this over 'identity.auth' alias in test setUp to avoid real JWT infrastructure.
 *
 * Usage in test (call after parent::setUp() so it overrides the service provider registration):
 *   $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
 */
final class FakeIdentityAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->header('X-Test-User-Id');

        if ($userId === null) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'UNAUTHORIZED', 'message' => 'Bearer token required.'],
            ], 401);
        }

        $request->attributes->set('identity.user_id', $userId);
        $request->attributes->set('identity.actor_type', $request->header('X-Test-Actor-Type', 'CUSTOMER'));
        $request->attributes->set('identity.session_id', $request->header('X-Test-Session-Id', (string) \Ramsey\Uuid\Uuid::uuid7()));

        // T182: populate identity.permissions from X-Test-Permissions header (comma-separated)
        $permHeader = $request->header('X-Test-Permissions');
        $permissions = $permHeader !== null
            ? array_filter(array_map('trim', explode(',', $permHeader)))
            : [];
        $request->attributes->set('identity.permissions', array_values($permissions));

        return $next($request);
    }
}
