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

describe('GET /v1/admin/users/{user_id}/profile', function (): void {

    it('returns 200 with profile when actor has ADMIN actor_type and profile.read permission', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        $response = $this->getJson("/v1/admin/users/{$targetId}/profile", [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'ADMIN',
            'X-Test-Permissions' => 'profile.read',
            'X-Correlation-ID'   => (string) Uuid::uuid7(),
        ]);

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.user_id'))->toBe($targetId);
    });

    it('returns 403 FORBIDDEN when actor is missing profile.read permission', function (): void {
        $userId   = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        // CUSTOMER actor without profile.read → 403 from ProfilePermissionMiddleware
        $response = $this->getJson("/v1/admin/users/{$targetId}/profile", [
            'X-Test-User-Id'    => $userId,
            'X-Test-Actor-Type' => 'CUSTOMER',
        ]);

        $response->assertStatus(403);
        expect($response->json('success'))->toBeFalse();
    });

    it('returns 404 when admin with profile.read accesses non-existent profile', function (): void {
        $nonExistentUserId = (string) Uuid::uuid7();

        $response = $this->getJson("/v1/admin/users/{$nonExistentUserId}/profile", [
            'X-Test-User-Id'     => (string) Uuid::uuid7(),
            'X-Test-Actor-Type'  => 'ADMIN',
            'X-Test-Permissions' => 'profile.read',
        ]);

        $response->assertStatus(404);
    });
});
