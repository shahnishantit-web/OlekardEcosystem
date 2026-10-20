<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Carbon\Carbon;
use Illuminate\Routing\Router;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

describe('Input validation — PUT /v1/users/profile/personal (T048)', function (): void {

    it('rejects invalid date_of_birth format with VALIDATION_ERROR', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'date_of_birth' => '15-01-1990', // wrong format (DD-MM-YYYY)
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('rejects non-date string for date_of_birth', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'date_of_birth' => 'not-a-date',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('rejects age below minimum_user_age_years with MINIMUM_AGE_VIOLATION', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        // Seed minimum age of 18 years in ConfigurationService
        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')
            ->with('profile.minimum_user_age_years')
            ->andReturn(18);
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        // DOB that yields age 17
        $underage = Carbon::now()->subYears(17)->format('Y-m-d');

        $response = $this->putJson('/v1/users/profile/personal', [
            'date_of_birth' => $underage,
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('MINIMUM_AGE_VIOLATION');
    });

    it('allows date_of_birth exactly at minimum age threshold', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')
            ->with('profile.minimum_user_age_years')
            ->andReturn(18);
        $mockConfig->shouldReceive('get')
            ->with('profile.photo.signed_url_ttl_seconds')
            ->andReturnNull();
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        // DOB exactly 18 years ago today
        $exactAge = Carbon::now()->subYears(18)->format('Y-m-d');

        $response = $this->putJson('/v1/users/profile/personal', [
            'date_of_birth' => $exactAge,
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(200);
    });

    it('skips minimum age check when profile.minimum_user_age_years is null (TBD)', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        // Config returns null — age check must be skipped (constitution P2: no invented fallback)
        $mockConfig = Mockery::mock(ConfigurationServiceInterface::class);
        $mockConfig->shouldReceive('get')
            ->with('profile.minimum_user_age_years')
            ->andReturnNull();
        $mockConfig->shouldReceive('get')->andReturnNull();
        app()->instance(ConfigurationServiceInterface::class, $mockConfig);

        // DOB yielding age 5 — would fail any non-null threshold
        $veryYoung = Carbon::now()->subYears(5)->format('Y-m-d');

        $response = $this->putJson('/v1/users/profile/personal', [
            'date_of_birth' => $veryYoung,
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        // Must succeed — null config means no age enforcement
        $response->assertStatus(200);
    });

    it('rejects invalid ISO 3166-1 alpha-2 country code format', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        // Lowercase — country must be 2 uppercase letters
        $response = $this->putJson('/v1/users/profile/personal', [
            'address' => ['country' => 'bw'],
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('rejects country code longer than 2 characters', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'address' => ['country' => 'BWA'],
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('accepts valid 2-letter uppercase country code', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'address' => ['country' => 'ZW'],
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(200);
    });

    it('rejects preferred_language exceeding 5 characters', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'preferred_language' => 'en-US-x', // 8 chars — too long
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('rejects invalid gender enum value', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'gender' => 'unknown',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('accepts all valid gender enum values', function (string $gender): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'gender' => $gender,
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(200);
    })->with(['male', 'female', 'non_binary', 'prefer_not_to_say']);

    it('rejects first_name exceeding 100 characters', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'first_name' => str_repeat('A', 101),
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('accepts empty request body (no-op update)', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [], [
            'X-Test-User-Id'  => $userId,
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(200); // No-op — returns current profile unchanged
    });
});
