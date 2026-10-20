<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Profile\Domain\Services\ProfileMetrics;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

describe('GET /v1/users/profile', function (): void {

    it('returns 200 with profile envelope; account_status is active; profile_photo_key absent', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'email'          => 'user@example.com',
            'mobile_number'  => '+26771234567',
            'account_status' => 'active',
        ]);

        $response = $this->getJson('/v1/users/profile', [
            'X-Test-User-Id'   => $userId,
            'X-Correlation-ID' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.user_id'))->toBe($userId);
        expect($response->json('data.account_status'))->toBe('active');
        expect($response->json('data'))->not->toHaveKey('profile_photo_key');
    });

    it('returns profile_photo_url as a signed URL when photo is set; raw key absent', function (): void {
        $userId = (string) Uuid::uuid7();

        // Seed profile with a photo key directly (bypasses fillable guard via DB)
        DB::connection('profile')->table('profile.user_profiles')->insert([
            'id'               => (string) Uuid::uuid7(),
            'user_id'          => $userId,
            'account_status'   => 'active',
            'profile_photo_key' => 'users/' . $userId . '/photo.jpg',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $response = $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(200);
        expect($response->json('data.profile_photo_url'))->not->toBeNull();
        expect($response->json('data'))->not->toHaveKey('profile_photo_key');
    });

    it('returns 404 PROFILE_NOT_FOUND when profile does not exist', function (): void {
        $userId = (string) Uuid::uuid7(); // no profile seeded

        $response = $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(404);
        expect($response->json('success'))->toBeFalse();
        expect($response->json('error.code'))->toBe('PROFILE_NOT_FOUND');
    });

    it('returns 401 when no token is provided', function (): void {
        $response = $this->getJson('/v1/users/profile');

        $response->assertStatus(401);
        expect($response->json('success'))->toBeFalse();
    });

    it('emits profile.view.success metric on successful profile read (T181)', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $mockCollector = Mockery::mock(MetricsCollectorInterface::class);
        $mockCollector->shouldReceive('increment')
            ->once()
            ->with('profile.view.success', ['actor_type' => 'customer']);

        // Bind a fresh ProfileMetrics instance that wraps the mock collector
        app()->instance(ProfileMetrics::class, new ProfileMetrics($mockCollector));

        $response = $this->getJson('/v1/users/profile', [
            'X-Test-User-Id'    => $userId,
            'X-Test-Actor-Type' => 'CUSTOMER',
        ]);

        $response->assertStatus(200);
        // Mockery will verify the expectation on teardown
    });

    it('does NOT emit profile.view.success metric on failed profile reads', function (): void {
        $userId = (string) Uuid::uuid7(); // no profile seeded

        $mockCollector = Mockery::mock(MetricsCollectorInterface::class);
        $mockCollector->shouldNotReceive('increment');

        app()->instance(ProfileMetrics::class, new ProfileMetrics($mockCollector));

        $response = $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userId]);
        $response->assertStatus(404);
    });
});
