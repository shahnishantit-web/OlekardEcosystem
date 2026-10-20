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
    $router->aliasMiddleware('throttle', \Illuminate\Routing\Middleware\ThrottleRequests::class);
});

// ---------------------------------------------------------------------------
// T154 — Privileged admin operation permission gates
// ---------------------------------------------------------------------------

describe('Admin privilege gates — account.suspend (T154)', function (): void {

    it('CUSTOMER actor cannot call suspend endpoint — returns 403', function (): void {
        $callerId = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$targetId}/suspend",
            ['reason' => 'Unauthorized attempt'],
            [
                'X-Test-User-Id'    => $callerId,
                'X-Test-Actor-Type' => 'CUSTOMER',
                'Idempotency-Key'   => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(403);
        expect($response->json('success'))->toBeFalse();
    });

    it('ADMIN actor without account.suspend permission returns 403', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$targetId}/suspend",
            ['reason' => 'Missing permission'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                // Deliberately omit account.suspend; only profile.read
                'X-Test-Permissions' => 'profile.read',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('ADMIN actor with account.suspend permission succeeds', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$targetId}/suspend",
            ['reason' => 'Valid suspend'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.suspend',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(200);
        expect($response->json('data.account_status'))->toBe('suspended');
    });
});

describe('Admin privilege gates — account.reactivate (T154)', function (): void {

    it('CUSTOMER actor cannot call reactivate endpoint — returns 403', function (): void {
        $callerId = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'suspended',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$targetId}/reactivate",
            [],
            [
                'X-Test-User-Id'    => $callerId,
                'X-Test-Actor-Type' => 'CUSTOMER',
                'Idempotency-Key'   => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(403);
        expect($response->json('success'))->toBeFalse();
    });

    it('ADMIN actor without account.reactivate permission returns 403', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'suspended',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$targetId}/reactivate",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'profile.read',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('ADMIN actor without account.reactivate cannot cancel-closure either', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'pending_closure',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$targetId}/cancel-closure",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.suspend',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });
});

describe('Admin privilege gates — account.close (T154)', function (): void {

    it('CUSTOMER actor cannot call close endpoint — returns 403', function (): void {
        $callerId = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$targetId}/close",
            [],
            [
                'X-Test-User-Id'    => $callerId,
                'X-Test-Actor-Type' => 'CUSTOMER',
                'Idempotency-Key'   => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(403);
        expect($response->json('success'))->toBeFalse();
    });

    it('ADMIN actor without account.close permission returns 403', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$targetId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.suspend,account.reactivate',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('ADMIN actor with account.close permission succeeds', function (): void {
        $adminId  = (string) Uuid::uuid7();
        $targetId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $targetId,
            'account_status' => 'active',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$targetId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.close',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(200);
        expect($response->json('data.account_status'))->toBe('closed');
    });
});
