<?php

declare(strict_types=1);

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

describe('Mass-assignment protection — PUT /v1/users/profile/personal (T047)', function (): void {

    it('rejects request containing "id" field with FIELD_NOT_PERMITTED', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'id'         => (string) Uuid::uuid7(),
            'first_name' => 'John',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('FIELD_NOT_PERMITTED');
    });

    it('rejects request containing "user_id" field with FIELD_NOT_PERMITTED', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'user_id'    => (string) Uuid::uuid7(),
            'first_name' => 'John',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('FIELD_NOT_PERMITTED');
    });

    it('rejects request containing "account_status" field with FIELD_NOT_PERMITTED', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'account_status' => 'suspended',
            'first_name'     => 'John',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('FIELD_NOT_PERMITTED');

        // Verify account_status was NOT changed
        $row = DB::connection('profile')->table('profile.user_profiles')->where('user_id', $userId)->first();
        expect($row->account_status)->toBe('active');
    });

    it('rejects request containing "email" field with FIELD_NOT_PERMITTED', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'email'      => 'hacker@example.com',
            'first_name' => 'John',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('FIELD_NOT_PERMITTED');

        // Email must NOT be changed
        $row = DB::connection('profile')->table('profile.user_profiles')->where('user_id', $userId)->first();
        expect($row->email)->toBeNull();
    });

    it('rejects request containing "mobile_number" field with FIELD_NOT_PERMITTED', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'mobile_number' => '+26771234567',
            'first_name'    => 'John',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('FIELD_NOT_PERMITTED');

        $row = DB::connection('profile')->table('profile.user_profiles')->where('user_id', $userId)->first();
        expect($row->mobile_number)->toBeNull();
    });

    it('rejects request containing "profile_photo_key" field with FIELD_NOT_PERMITTED', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'profile_photo_key' => 'users/hacked/photo.jpg',
            'first_name'        => 'John',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('FIELD_NOT_PERMITTED');

        $row = DB::connection('profile')->table('profile.user_profiles')->where('user_id', $userId)->first();
        expect($row->profile_photo_key)->toBeNull();
    });

    it('rejects any field starting with "pending_" with FIELD_NOT_PERMITTED', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'pending_email'    => 'hacker@example.com',
            'first_name'       => 'John',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('FIELD_NOT_PERMITTED');
    });

    it('does not disclose that account_status was rejected in a leaky error message', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'account_status' => 'closed',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        // The error must not reveal the attempted value
        expect($response->json('error.code'))->toBe('FIELD_NOT_PERMITTED');
        expect($response->getContent())->not->toContain('closed');
    });

    it('accepts a request with only permitted fields', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->putJson('/v1/users/profile/personal', [
            'first_name'        => 'Permitted',
            'last_name'         => 'Fields',
            'preferred_language' => 'fr',
        ], ['X-Test-User-Id' => $userId, 'Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(200);
        expect($response->json('data.first_name'))->toBe('Permitted');
    });
});
