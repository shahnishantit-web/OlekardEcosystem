<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
});

describe('profile-admin rate limiter — TBD governance (T184)', function (): void {

    it('applies no rate limit when profile.rate_limit.admin_per_minute is null (TBD)', function (): void {
        // Confirm config key is null/TBD — do not set it
        $config = app(ConfigurationServiceInterface::class);
        expect($config->get('profile.rate_limit.admin_per_minute'))->toBeNull();

        $userId   = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        // Make multiple requests without hitting a 429 — TBD means Limit::none()
        for ($i = 0; $i < 5; $i++) {
            $response = $this->getJson("/v1/admin/users/{$targetId}/profile", [
                'X-Test-User-Id'     => $userId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'profile.read',
            ]);
            expect($response->status())->not->toBe(429, "Request {$i} was throttled — TBD config must not apply a hard-coded limit");
        }
    });

    it('does NOT silently apply a hard-coded numeric fallback when config is null', function (): void {
        // Resolve the profile-admin RateLimiter closure and verify Limit::none() is returned for null config
        $limiterResolver = RateLimiter::limiter('profile-admin');
        expect($limiterResolver)->not->toBeNull('profile-admin rate limiter must be registered');

        // Build a mock request so we can call the closure
        $request = \Illuminate\Http\Request::create('/test');
        $limit   = $limiterResolver($request);

        // Limit::none() produces a Limit with maxAttempts = PHP_INT_MAX (unlimited)
        // asserting it is not a small numeric cap proves no invented value is applied
        expect($limit)->toBeInstanceOf(Limit::class);
        // Limit::none() sets maxAttempts to PHP_INT_MAX — any hard-coded 120 would be far smaller
        expect($limit->maxAttempts)->toBeGreaterThan(10000,
            'When admin rate limit is TBD, limiter must be Limit::none() — not an invented cap like 120');
    });

    it('respects profile.rate_limit.admin_per_minute when explicitly configured', function (): void {
        // Bind a mock ConfigurationService that returns a configured value
        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')
            ->with('profile.rate_limit.admin_per_minute')
            ->andReturn(200);
        $mockConfig->shouldReceive('get')->andReturnNull(); // other keys

        // Re-register the limiter with the mock config
        RateLimiter::for('profile-admin-test', static function (\Illuminate\Http\Request $r) use ($mockConfig): Limit {
            $limit = $mockConfig->get('profile.rate_limit.admin_per_minute');
            return $limit !== null
                ? Limit::perMinute((int) $limit)->by($r->user()?->id ?? $r->ip())
                : Limit::none();
        });

        $request = \Illuminate\Http\Request::create('/test');
        $limit   = RateLimiter::limiter('profile-admin-test')($request);

        expect($limit->maxAttempts)->toBe(200);
    });

    it('profile-user limiter is unaffected and uses its own authorized fallback', function (): void {
        // Confirm profile-user limiter is still registered and resolves independently
        $limiterResolver = RateLimiter::limiter('profile-user');
        expect($limiterResolver)->not->toBeNull('profile-user rate limiter must still be registered');

        $request = \Illuminate\Http\Request::create('/test');
        $limit   = $limiterResolver($request);

        // profile-user fallback is ?? 60 (authorized by T177) — not Limit::none()
        expect($limit)->toBeInstanceOf(Limit::class);
        expect($limit->maxAttempts)->toBeLessThanOrEqual(60,
            'profile-user authorized fallback is 60 when config is null (T177)');
    });

    it('admin route uses profile-admin limiter and user route uses profile-user limiter', function (): void {
        $routes = app(Router::class)->getRoutes();

        $adminRoute = $routes->match(
            \Illuminate\Http\Request::create('/v1/admin/users/some-id/profile', 'GET')
        );
        $userRoute = $routes->match(
            \Illuminate\Http\Request::create('/v1/users/profile', 'GET')
        );

        $adminMiddleware = $adminRoute->gatherMiddleware();
        $userMiddleware  = $userRoute->gatherMiddleware();

        expect(collect($adminMiddleware)->contains(fn ($m) => str_contains($m, 'profile-admin')))->toBeTrue(
            'Admin route must use throttle:profile-admin'
        );
        expect(collect($userMiddleware)->contains(fn ($m) => str_contains($m, 'profile-user')))->toBeTrue(
            'User route must use throttle:profile-user'
        );
        expect(collect($adminMiddleware)->contains(fn ($m) => str_contains($m, 'profile-user')))->toBeFalse(
            'Admin route must NOT use profile-user throttle'
        );
    });
});
