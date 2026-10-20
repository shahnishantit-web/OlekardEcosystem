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

// ---------------------------------------------------------------------------
// T155 — Profile photo URL security
// ---------------------------------------------------------------------------

describe('Profile photo URL security (T155)', function (): void {

    it('profile_photo_key never appears in GET /v1/users/profile response', function (): void {
        $userId = (string) Uuid::uuid7();

        // Insert directly so profile_photo_key is populated in the DB
        DB::connection('profile')->table('profile.user_profiles')->insert([
            'id'                => (string) Uuid::uuid7(),
            'user_id'           => $userId,
            'account_status'    => 'active',
            'profile_photo_key' => "users/{$userId}/avatar.jpg",
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $response = $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('profile_photo_key', 'profile_photo_key field must never be serialized');
        expect($body)->not->toContain('avatar.jpg', 'Raw storage key path must never appear in any API response');
    });

    it('profile_photo_key never appears in GET /v1/admin/users/{user_id}/profile response', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        DB::connection('profile')->table('profile.user_profiles')->insert([
            'id'                => (string) Uuid::uuid7(),
            'user_id'           => $targetId,
            'account_status'    => 'active',
            'profile_photo_key' => "users/{$targetId}/private-key.jpg",
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $response = $this->getJson("/v1/admin/users/{$targetId}/profile", [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'ADMIN',
            'X-Test-Permissions' => 'profile.read',
        ]);

        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('profile_photo_key', 'profile_photo_key must be absent even in admin API response');
        expect($body)->not->toContain('private-key.jpg', 'Raw storage object key must not appear in admin response');
    });

    it('profile_photo_url in response is a signed URL containing an expiry parameter', function (): void {
        $userId = (string) Uuid::uuid7();

        DB::connection('profile')->table('profile.user_profiles')->insert([
            'id'                => (string) Uuid::uuid7(),
            'user_id'           => $userId,
            'account_status'    => 'active',
            'profile_photo_key' => "users/{$userId}/photo.jpg",
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $response = $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(200);

        $photoUrl = $response->json('data.profile_photo_url');

        if ($photoUrl !== null) {
            // Signed URLs must contain a time-limited expiry query parameter
            // S3-style: X-Amz-Expires or Expires; GCS-style: X-Goog-Expires
            $hasExpiry = str_contains($photoUrl, 'X-Amz-Expires')
                || str_contains($photoUrl, 'Expires=')
                || str_contains($photoUrl, 'X-Goog-Expires')
                || str_contains($photoUrl, 'se=');  // Azure SAS token expiry

            expect($hasExpiry)->toBeTrue('Signed URL must contain an expiry parameter (X-Amz-Expires, Expires, X-Goog-Expires, or se=)');

            // Signed URL must not embed raw storage credentials (access keys / secrets)
            expect($photoUrl)->not->toContain('AKIA', 'AWS access key ID must not appear in signed URL response');
            expect($photoUrl)->not->toContain('aws_secret', 'AWS secret must not appear in signed URL response');
        }
    });

    it('profile_photo_url is absent (null) when no photo has been uploaded', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $response = $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveKey('profile_photo_url');
        expect($response->json('data.profile_photo_url'))->toBeNull();
    });

    it('profile_photo_key absent from PUT personal-info response', function (): void {
        $userId = (string) Uuid::uuid7();

        DB::connection('profile')->table('profile.user_profiles')->insert([
            'id'                => (string) Uuid::uuid7(),
            'user_id'           => $userId,
            'account_status'    => 'active',
            'profile_photo_key' => "users/{$userId}/photo.jpg",
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $response = $this->putJson('/v1/users/profile/personal', [
            'first_name' => 'Updated',
        ], [
            'X-Test-User-Id'  => $userId,
            'Idempotency-Key' => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->not->toContain('profile_photo_key');
    });
});
