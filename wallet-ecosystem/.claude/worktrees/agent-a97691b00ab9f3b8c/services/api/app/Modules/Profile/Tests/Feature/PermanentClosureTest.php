<?php

declare(strict_types=1);

use App\Modules\Profile\Domain\Contracts\FinancialObligationContract;
use App\Modules\Profile\Domain\Contracts\ObligationCheckResult;
use App\Modules\Profile\Infrastructure\Repositories\EloquentAccountStatusHistoryRepository;
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
// T110 — Happy-path feature tests for POST /v1/admin/users/{user_id}/close
// ---------------------------------------------------------------------------

describe('POST /v1/admin/users/{user_id}/close — happy path (T110)', function (): void {

    beforeEach(function (): void {
        // Override the obligation stub with a mock that clears all obligations
        $mock = Mockery::mock(FinancialObligationContract::class);
        $mock->shouldReceive('checkObligations')
            ->andReturn(new ObligationCheckResult(hasObligations: false, blockedBy: []));
        app()->instance(FinancialObligationContract::class, $mock);
    });

    it('transitions pending_closure account to closed and returns 200 with account_status = closed', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/close",
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

    it('persists account_status = closed in the database after successful close', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.close',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('closed');
    });

    it('emits Profile.Account.Closed to outbox on successful permanent close', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.close',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.Closed')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->not->toBeNull('Profile.Account.Closed must be written to outbox on successful close');

        $payload = json_decode($event->payload, true);
        expect($payload['user_id'])->toBe($userId);
    });

    it('writes account_status_history row from=pending_closure to=closed with admin actor', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.close',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $history = app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId);
        expect($history)->toHaveCount(1);

        $entry = $history->first();
        expect($entry->from_status)->toBe('pending_closure');
        expect($entry->to_status)->toBe('closed');
        expect($entry->actor_id)->toBe($adminId);
        expect($entry->actor_type)->toBe('ADMIN');
        expect($entry->closure_obligations)->toBeNull();
    });

    it('subsequent close of already-closed account returns 400 INVALID_STATUS_TRANSITION', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        // First close succeeds
        $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.close',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        )->assertStatus(200);

        // Second close with a fresh idempotency key hits the 400
        $response = $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.close',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');
    });

    it('same Idempotency-Key replays 200 without double-writing history or double-emitting Closed event', function (): void {
        $userId   = (string) Uuid::uuid7();
        $adminId  = (string) Uuid::uuid7();
        $idempKey = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $headers = [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'ADMIN',
            'X-Test-Permissions' => 'account.close',
            'Idempotency-Key'    => $idempKey,
        ];

        $first  = $this->postJson("/v1/admin/users/{$userId}/close", [], $headers);
        $second = $this->postJson("/v1/admin/users/{$userId}/close", [], $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);

        expect(
            app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId)
        )->toHaveCount(1);

        $count = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.Closed')
            ->where('aggregate_id', $userId)
            ->count();
        expect($count)->toBe(1, 'Profile.Account.Closed must be emitted exactly once even with duplicate requests');
    });

});

// ---------------------------------------------------------------------------
// T111 — Security tests for POST /v1/admin/users/{user_id}/close
// ---------------------------------------------------------------------------

describe('POST /v1/admin/users/{user_id}/close — security (T111)', function (): void {

    beforeEach(function (): void {
        // Reset to the real stub (always blocks) for all security tests unless overridden
        // Security tests generally don't reach the obligation check, so this is fine.
    });

    it('returns 403 FORBIDDEN when admin lacks account.close permission', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'  => $adminId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
                // No X-Test-Permissions → permission middleware rejects
            ],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('returns 401 UNAUTHENTICATED when no auth token is provided', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            ['Idempotency-Key' => (string) Uuid::uuid7()],
        );

        $response->assertStatus(401);
    });

    it('returns 400 INVALID_STATUS_TRANSITION when account is active (not pending_closure)', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        // Override obligation check so status validation is the failure, not the gate
        $mock = Mockery::mock(FinancialObligationContract::class);
        $mock->shouldReceive('checkObligations')
            ->andReturn(new ObligationCheckResult(hasObligations: false, blockedBy: []));
        app()->instance(FinancialObligationContract::class, $mock);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.close',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');
    });

    it('returns 400 INVALID_STATUS_TRANSITION when account is suspended (not pending_closure)', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'suspended',
        ]);

        // Override obligation check so status validation is the failure, not the gate
        $mock = Mockery::mock(FinancialObligationContract::class);
        $mock->shouldReceive('checkObligations')
            ->andReturn(new ObligationCheckResult(hasObligations: false, blockedBy: []));
        app()->instance(FinancialObligationContract::class, $mock);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.close',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');
    });

    it('returns 422 VALIDATION_ERROR when Idempotency-Key header is missing', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.close',
                // No Idempotency-Key
            ],
        );

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('account status is unchanged when close is rejected due to missing permission', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'  => $adminId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
                // No permissions
            ],
        );

        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('pending_closure', 'Account must remain pending_closure when close is rejected');
    });

    it('Profile.Account.Closed is not emitted when close is rejected due to missing permission', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'  => $adminId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
                // No permissions
            ],
        );

        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.Closed')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->toBeNull('Profile.Account.Closed must NOT be emitted when permission check fails');
    });

});
