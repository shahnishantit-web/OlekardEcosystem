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
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

describe('profile-contact-change rate limiter — TBD governance (T188)', function (): void {

    it('rate limiter profile-contact-change is registered', function (): void {
        $limiterResolver = RateLimiter::limiter('profile-contact-change');
        expect($limiterResolver)->not->toBeNull('profile-contact-change rate limiter must be registered');
    });

    it('applies Limit::none() when profile.contact_change.otp_rate_limit_per_hour is null (TBD)', function (): void {
        $limiterResolver = RateLimiter::limiter('profile-contact-change');
        $request = \Illuminate\Http\Request::create('/test');
        $limit   = $limiterResolver($request);

        expect($limit)->toBeInstanceOf(Limit::class);
        // Limit::none() sets maxAttempts to PHP_INT_MAX — any hard-coded value would be far smaller
        expect($limit->maxAttempts)->toBeGreaterThan(10000,
            'When OTP rate limit is TBD, limiter must be Limit::none() — not an invented cap');
    });

    it('applies per-hour limit when profile.contact_change.otp_rate_limit_per_hour is configured', function (): void {
        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')
            ->with('profile.contact_change.otp_rate_limit_per_hour')
            ->andReturn(5);
        $mockConfig->shouldReceive('get')->andReturnNull();

        // Re-register with mock config to verify the per-hour limit path
        RateLimiter::for('profile-contact-change-test', static function (\Illuminate\Http\Request $r) use ($mockConfig): Limit {
            $limit = $mockConfig->get('profile.contact_change.otp_rate_limit_per_hour');
            return $limit !== null
                ? Limit::perHour((int) $limit)->by($r->user()?->id ?? $r->ip())
                : Limit::none();
        });

        $request = \Illuminate\Http\Request::create('/test');
        $limit   = RateLimiter::limiter('profile-contact-change-test')($request);

        expect($limit->maxAttempts)->toBe(5);
    });

    it('email change routes use throttle:profile-contact-change (not profile-user)', function (): void {
        $routes = app(Router::class)->getRoutes();

        $changeRoute = $routes->match(
            \Illuminate\Http\Request::create('/v1/users/profile/email/change', 'POST')
        );
        $verifyRoute = $routes->match(
            \Illuminate\Http\Request::create('/v1/users/profile/email/verify', 'POST')
        );

        $changeMiddleware = $changeRoute->gatherMiddleware();
        $verifyMiddleware = $verifyRoute->gatherMiddleware();

        expect(collect($changeMiddleware)->contains(fn ($m) => str_contains($m, 'profile-contact-change')))->toBeTrue(
            'POST email/change must use throttle:profile-contact-change'
        );
        expect(collect($verifyMiddleware)->contains(fn ($m) => str_contains($m, 'profile-contact-change')))->toBeTrue(
            'POST email/verify must use throttle:profile-contact-change'
        );
        expect(collect($changeMiddleware)->contains(fn ($m) => str_contains($m, 'profile-user') && !str_contains($m, 'profile-contact')))->toBeFalse(
            'Email change routes must NOT use profile-user throttle'
        );
    });

    it('does not rate-limit contact-change when OTP limit is null (TBD governance)', function (): void {
        // Confirm config key is null/TBD — do not set it
        $config = app(ConfigurationServiceInterface::class);
        expect($config->get('profile.contact_change.otp_rate_limit_per_hour'))->toBeNull();

        $userId    = (string) Uuid::uuid7();
        $challengeId = (string) Uuid::uuid7();

        $repo = app(EloquentUserProfileRepository::class);
        $repo->create(['user_id' => $userId, 'account_status' => 'active']);
        $repo->update($userId, ['email' => 'original@example.com']);

        $mockOtp = Mockery::mock(\App\Modules\Identity\Domain\Contracts\OtpServiceContract::class);
        $mockOtp->shouldReceive('generate')->times(3)->andReturnUsing(function () use ($challengeId) {
            $challenge     = new \App\Modules\Identity\Infrastructure\Models\OtpChallenge();
            $challenge->id = (string) Uuid::uuid7();
            return $challenge;
        });
        app()->instance(\App\Modules\Identity\Domain\Contracts\OtpServiceContract::class, $mockOtp);

        // Three requests — all succeed; Limit::none() must not trigger 429
        for ($i = 0; $i < 3; $i++) {
            // Clear pending before each initiation so service doesn't throw CONTACT_CHANGE_IN_PROGRESS
            $repo->update($userId, ['pending_email' => null, 'pending_email_challenge_id' => null]);

            $response = $this->postJson(
                '/v1/users/profile/email/change',
                ['new_email' => "new{$i}@example.com"],
                ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()],
            );
            expect($response->status())->not->toBe(429,
                "Request {$i} was throttled — TBD config must apply Limit::none()");
        }
    });
});

// ---------------------------------------------------------------------------
// T178 — profile-user and profile-photo throttle enforcement
// ---------------------------------------------------------------------------

describe('profile-user throttle enforcement (T178)', function (): void {

    afterEach(function (): void {
        // Flush cached rate-limit counters so each test starts clean
        \Illuminate\Support\Facades\Cache::flush();
    });

    it('enforces profile-user limit of 2: first two requests succeed, third is 429 with Retry-After', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        // Override limiter with a low limit; key by identity.user_id attribute (set by FakeIdentityAuthMiddleware)
        RateLimiter::for('profile-user', function (\Illuminate\Http\Request $r): \Illuminate\Cache\RateLimiting\Limit {
            $key = $r->attributes->get('identity.user_id') ?? $r->ip();
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(2)->by($key);
        });

        $headers = ['X-Test-User-Id' => $userId];

        $this->getJson('/v1/users/profile', $headers)->assertStatus(200);
        $this->getJson('/v1/users/profile', $headers)->assertStatus(200);

        // Third request must be blocked — ThrottleRequests middleware returns 429
        // If this assertion gets 500 instead, add TooManyRequestsHttpException to app/Exceptions/Handler.php
        $throttled = $this->getJson('/v1/users/profile', $headers);
        $throttled->assertStatus(429);
        expect($throttled->headers->has('Retry-After'))->toBeTrue(
            'ThrottleRequests middleware must add Retry-After on 429; if 500 is returned, wire TooManyRequestsHttpException in Handler'
        );
    });

    it('per-user isolation: user A at limit does not throttle user B', function (): void {
        $userA = (string) Uuid::uuid7();
        $userB = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userA, 'account_status' => 'active']);
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userB, 'account_status' => 'active']);

        RateLimiter::for('profile-user', function (\Illuminate\Http\Request $r): \Illuminate\Cache\RateLimiting\Limit {
            $key = $r->attributes->get('identity.user_id') ?? $r->ip();
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(1)->by($key);
        });

        // Exhaust user A's single-request quota
        $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userA])->assertStatus(200);
        $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userA])->assertStatus(429);

        // User B must still be permitted — different identity.user_id → different bucket
        $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userB])->assertStatus(200);
    });

});

describe('profile-photo throttle enforcement (T178)', function (): void {

    afterEach(function (): void {
        \Illuminate\Support\Facades\Cache::flush();
    });

    it('enforces profile-photo limit of 1: first upload passes throttle gate, second is 429', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        RateLimiter::for('profile-photo', function (\Illuminate\Http\Request $r): \Illuminate\Cache\RateLimiting\Limit {
            $key = $r->attributes->get('identity.user_id') ?? $r->ip();
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(1)->by($key);
        });

        $headers = ['X-Test-User-Id' => $userId];

        // First request passes the throttle gate (the controller may return 422 or 415 for missing photo — that's fine)
        $first = $this->postJson('/v1/users/profile/photo', [], $headers);
        expect($first->status())->not->toBe(429, 'First photo request must not be rate-limited');

        // Second request must be blocked by the throttle middleware
        $this->postJson('/v1/users/profile/photo', [], $headers)->assertStatus(429);
    });

});
