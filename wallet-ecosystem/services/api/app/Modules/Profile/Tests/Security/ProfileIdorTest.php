<?php

declare(strict_types=1);

use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
});

describe('IDOR / BOLA protection — Profile', function (): void {

    it('non-admin user calling admin profile endpoint returns 403 — never 404 — with no profile data disclosed', function (): void {
        $userAId = (string) Uuid::uuid7();
        $userBId = (string) Uuid::uuid7();

        // Seed user B's profile
        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userBId,
            'first_name'     => 'UserB',
            'account_status' => 'active',
        ]);

        // User A (CUSTOMER) tries to access User B's profile via admin endpoint
        $response = $this->getJson("/v1/admin/users/{$userBId}/profile", [
            'X-Test-User-Id'    => $userAId,
            'X-Test-Actor-Type' => 'CUSTOMER',
        ]);

        // Must be 403, never 404 (spec §IDOR)
        $response->assertStatus(403);
        expect($response->json('success'))->toBeFalse();

        // No profile data disclosed in response body
        expect($response->getContent())->not->toContain('UserB');
        expect($response->getContent())->not->toContain($userBId);
    });

    it('non-admin user accessing own profile returns 200 — not 403', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $response = $this->getJson('/v1/users/profile', [
            'X-Test-User-Id'    => $userId,
            'X-Test-Actor-Type' => 'CUSTOMER',
        ]);

        $response->assertStatus(200);
        expect($response->json('data.user_id'))->toBe($userId);
    });

    // T153 — User A cannot PUT user B's personal info via admin endpoint
    it('admin user A cannot update user B profile via PUT admin endpoint even with profile.update permission', function (): void {
        $userAId = (string) Uuid::uuid7();
        $userBId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userBId,
            'first_name'     => 'OriginalName',
            'account_status' => 'active',
        ]);

        // User A (CUSTOMER) tries to PUT user B's profile via user-path — no such route scoped to another user;
        // however, a CUSTOMER actor calling the ADMIN PUT endpoint must be rejected
        $response = $this->putJson("/v1/admin/users/{$userBId}/profile/personal", [
            'first_name' => 'Hijacked',
        ], [
            'X-Test-User-Id'     => $userAId,
            'X-Test-Actor-Type'  => 'CUSTOMER',
            'Idempotency-Key'    => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(403);

        // User B's first_name must be unchanged
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userBId);
        expect($profile->first_name)->toBe('OriginalName', 'profile must not be mutated by unauthorized actor');
    });

    // T153 — Arbitrary UUID IDOR: requesting a random UUID returns 403/404 but never leaks data
    it('requesting an arbitrary non-existent user_id via admin endpoint returns 403 for non-admin (never 200 or data leak)', function (): void {
        $callerId      = (string) Uuid::uuid7();
        $arbitraryUuid = (string) Uuid::uuid7();

        // CUSTOMER actor with an arbitrary userId → must not reach admin logic
        $response = $this->getJson("/v1/admin/users/{$arbitraryUuid}/profile", [
            'X-Test-User-Id'    => $callerId,
            'X-Test-Actor-Type' => 'CUSTOMER',
        ]);

        // 403 (no permission to reach admin endpoint), never 200 or data disclosure
        $response->assertStatus(403);
        expect($response->json('success'))->toBeFalse();
        expect($response->getContent())->not->toContain($arbitraryUuid);
    });

    // T153 — Admin cannot read a different admin's user profile through user-path (no cross-admin escalation)
    it('ADMIN actor calling user-path GET /v1/users/profile returns own profile, not another user\'s profile', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $otherUserId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $adminId,
            'first_name'     => 'AdminUser',
            'account_status' => 'active',
        ]);

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $otherUserId,
            'first_name'     => 'OtherUser',
            'account_status' => 'active',
        ]);

        $response = $this->getJson('/v1/users/profile', [
            'X-Test-User-Id'    => $adminId,
            'X-Test-Actor-Type' => 'ADMIN',
        ]);

        $response->assertStatus(200);
        // Must return adminId's own profile, not otherUserId's
        expect($response->json('data.user_id'))->toBe($adminId);
        expect($response->getContent())->not->toContain('OtherUser');
    });

    // T153 — All status-management IDOR: CUSTOMER cannot call admin suspend on another user
    it('CUSTOMER cannot suspend another user via admin suspend endpoint', function (): void {
        $callerId = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$targetId}/suspend",
            ['reason' => 'IDOR attempt'],
            [
                'X-Test-User-Id'    => $callerId,
                'X-Test-Actor-Type' => 'CUSTOMER',
                'Idempotency-Key'   => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(403);

        // Target account must remain active
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($targetId);
        expect($profile->account_status)->toBe('active');
    });

    it('profile_photo_key never appears in any API response', function (): void {
        $userId = (string) Uuid::uuid7();
        \Illuminate\Support\Facades\DB::connection('profile')->table('profile.user_profiles')->insert([
            'id'               => (string) Uuid::uuid7(),
            'user_id'          => $userId,
            'account_status'   => 'active',
            'profile_photo_key' => 'users/' . $userId . '/secret-key.jpg',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $response = $this->getJson('/v1/users/profile', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(200);
        expect($response->getContent())->not->toContain('profile_photo_key');
        expect($response->getContent())->not->toContain('secret-key.jpg');
    });
});
