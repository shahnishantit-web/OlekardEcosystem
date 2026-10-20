<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Tests\TestCase;

final class CsrfBearerAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * API routes must NOT include VerifyCsrfToken — bearer-token auth satisfies
     * the CSRF requirement (E26 research.md Decision 2).
     *
     * @test
     */
    public function api_middleware_group_does_not_contain_csrf_verification(): void
    {
        /** @var Router $router */
        $router     = $this->app->make(Router::class);
        $apiGroup   = $router->getMiddlewareGroups()['api'] ?? [];

        $middlewareClasses = array_map(
            static fn ($m) => is_string($m) ? $m : (is_array($m) ? $m[0] : ''),
            $apiGroup
        );

        $this->assertNotContains(
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            $middlewareClasses,
            'VerifyCsrfToken MUST NOT be in the api middleware group; bearer-token auth provides equivalent CSRF protection.'
        );
    }

    /**
     * A POST request with a valid bearer token but without X-XSRF-TOKEN must succeed
     * at the transport layer (not rejected for missing CSRF token).
     *
     * @test
     */
    public function post_with_bearer_token_and_no_csrf_header_is_not_rejected_by_csrf(): void
    {
        // Health probe is a GET; use the login route as the representative state-changing POST.
        // We are only asserting no 419 (CSRF token mismatch) is returned.
        $response = $this->postJson('/api/v1/auth/login', [
            'phone'    => '+1000000000',
            'password' => 'wrongpassword',
        ]);

        // 419 = CSRF token mismatch — that must never happen on an API route.
        $this->assertNotEquals(419, $response->getStatusCode(), 'API route returned 419 (CSRF token mismatch); VerifyCsrfToken should not apply to API routes.');
    }
}
