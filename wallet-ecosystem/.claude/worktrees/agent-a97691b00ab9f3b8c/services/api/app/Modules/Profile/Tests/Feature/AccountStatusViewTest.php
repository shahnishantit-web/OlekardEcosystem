<?php

declare(strict_types=1);

use App\Modules\Profile\Infrastructure\Repositories\EloquentAccountStatusHistoryRepository;
use App\Modules\Profile\Infrastructure\Repositories\EloquentUserProfileRepository;
use Illuminate\Routing\Router;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    /** @var Router $router */
    $router = app(Router::class);
    $router->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

describe('GET /v1/users/profile/status (T084)', function (): void {

    it('returns 200 with account_status and history; actor_id absent from user-path history', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        $profileRepo = app(EloquentUserProfileRepository::class);
        $historyRepo = app(EloquentAccountStatusHistoryRepository::class);

        $profileRepo->create(['user_id' => $userId, 'account_status' => 'suspended']);

        $historyRepo->appendTransition([
            'user_id'        => $userId,
            'from_status'    => 'active',
            'to_status'      => 'suspended',
            'reason'         => 'Policy violation',
            'actor_id'       => $adminId,
            'actor_type'     => 'ADMIN',
            'correlation_id' => (string) Uuid::uuid7(),
        ]);

        $response = $this->getJson('/v1/users/profile/status', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.account_status'))->toBe('suspended');
        expect($response->json('data.history'))->toBeArray();
        expect($response->json('data.history'))->toHaveCount(1);

        $entry = $response->json('data.history.0');
        expect($entry['from_status'])->toBe('active');
        expect($entry['to_status'])->toBe('suspended');
        expect($entry['reason'])->toBe('Policy violation');
        expect($entry['actor_type'])->toBe('ADMIN');
        expect($entry)->not->toHaveKey('actor_id', 'actor_id must be absent on user-path history');
        expect($entry['created_at'])->not->toBeNull();
    });

    it('returns history ordered by created_at DESC (most recent first)', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        $profileRepo = app(EloquentUserProfileRepository::class);
        $historyRepo = app(EloquentAccountStatusHistoryRepository::class);

        $profileRepo->create(['user_id' => $userId, 'account_status' => 'active']);

        // Append two history entries; second should appear first in response
        $historyRepo->appendTransition([
            'user_id'        => $userId,
            'from_status'    => 'active',
            'to_status'      => 'suspended',
            'reason'         => 'First transition',
            'actor_id'       => $adminId,
            'actor_type'     => 'ADMIN',
            'correlation_id' => (string) Uuid::uuid7(),
        ]);
        $historyRepo->appendTransition([
            'user_id'        => $userId,
            'from_status'    => 'suspended',
            'to_status'      => 'active',
            'reason'         => null,
            'actor_id'       => $adminId,
            'actor_type'     => 'ADMIN',
            'correlation_id' => (string) Uuid::uuid7(),
        ]);

        $response = $this->getJson('/v1/users/profile/status', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(200);
        $history = $response->json('data.history');
        expect($history)->toHaveCount(2);
        // Most recent transition (suspended → active) appears first
        expect($history[0]['to_status'])->toBe('active');
        expect($history[1]['to_status'])->toBe('suspended');
    });

    it('returns 401 when no auth token provided', function (): void {
        $response = $this->getJson('/v1/users/profile/status');
        $response->assertStatus(401);
    });

    it('returns 404 PROFILE_NOT_FOUND when profile does not exist', function (): void {
        $userId = (string) Uuid::uuid7();

        $response = $this->getJson('/v1/users/profile/status', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(404);
        expect($response->json('error.code'))->toBe('PROFILE_NOT_FOUND');
    });

    it('returns empty history array when profile has no transitions', function (): void {
        $userId = (string) Uuid::uuid7();
        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->getJson('/v1/users/profile/status', ['X-Test-User-Id' => $userId]);

        $response->assertStatus(200);
        expect($response->json('data.account_status'))->toBe('active');
        expect($response->json('data.history'))->toBe([]);
    });

});

describe('GET /v1/admin/users/{user_id}/profile/status (T084)', function (): void {

    it('returns 200 with account_status and history including actor_id on admin path', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        $profileRepo = app(EloquentUserProfileRepository::class);
        $historyRepo = app(EloquentAccountStatusHistoryRepository::class);

        $profileRepo->create(['user_id' => $userId, 'account_status' => 'suspended']);
        $historyRepo->appendTransition([
            'user_id'        => $userId,
            'from_status'    => 'active',
            'to_status'      => 'suspended',
            'reason'         => 'Fraud detection',
            'actor_id'       => $adminId,
            'actor_type'     => 'ADMIN',
            'correlation_id' => (string) Uuid::uuid7(),
        ]);

        $response = $this->getJson(
            "/v1/admin/users/{$userId}/profile/status",
            ['X-Test-User-Id' => $adminId, 'X-Test-Permissions' => 'profile.read'],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.account_status'))->toBe('suspended');

        $entry = $response->json('data.history.0');
        expect($entry['from_status'])->toBe('active');
        expect($entry['to_status'])->toBe('suspended');
        expect($entry['reason'])->toBe('Fraud detection');
        expect($entry['actor_type'])->toBe('ADMIN');
        expect($entry['actor_id'])->toBe($adminId, 'actor_id must be present on admin-path history');
        expect($entry['created_at'])->not->toBeNull();
    });

    it('returns 403 FORBIDDEN when admin token lacks profile.read permission', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        $response = $this->getJson(
            "/v1/admin/users/{$userId}/profile/status",
            ['X-Test-User-Id' => $adminId],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('returns 401 when no auth token provided on admin path', function (): void {
        $userId = (string) Uuid::uuid7();
        $response = $this->getJson("/v1/admin/users/{$userId}/profile/status");
        $response->assertStatus(401);
    });

    it('returns 404 PROFILE_NOT_FOUND when target profile does not exist on admin path', function (): void {
        $adminId = (string) Uuid::uuid7();
        $userId  = (string) Uuid::uuid7();

        $response = $this->getJson(
            "/v1/admin/users/{$userId}/profile/status",
            ['X-Test-User-Id' => $adminId, 'X-Test-Permissions' => 'profile.read'],
        );

        $response->assertStatus(404);
        expect($response->json('error.code'))->toBe('PROFILE_NOT_FOUND');
    });

});
