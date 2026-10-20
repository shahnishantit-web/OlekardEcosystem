<?php

declare(strict_types=1);

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
// T104 — User closure request with stub always blocked (obligation gate)
// ---------------------------------------------------------------------------

describe('POST /v1/users/profile/closure/request — obligation gate (T104)', function (): void {

    it('returns 409 CLOSURE_BLOCKED_BY_OBLIGATIONS when stub is active', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $response = $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('CLOSURE_BLOCKED_BY_OBLIGATIONS');
    });

    it('response body contains blocked_by: [obligations_check_unavailable]', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $response = $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        expect($response->json('error.blocked_by'))->toBe(['obligations_check_unavailable']);
    });

    it('account status remains active when closure is blocked by stub', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('active');
    });

    it('emits Profile.Account.ClosureBlocked when closure is blocked by stub', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.ClosureBlocked')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->not->toBeNull('Profile.Account.ClosureBlocked must be written to outbox when stub blocks');
        $payload = json_decode($event->payload, true);
        expect($payload['blocked_by'])->toBe(['obligations_check_unavailable']);
    });

    it('does not transition to pending_closure when stub blocks', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.ClosureRequested')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->toBeNull('ClosureRequested must NOT be emitted when gate blocks');
    });

    it('missing Idempotency-Key returns 422 VALIDATION_ERROR', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $response = $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            ['X-Test-User-Id' => $userId],
        );

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

});

// ---------------------------------------------------------------------------
// T195 — Idempotency on POST /v1/users/profile/closure/request
// ---------------------------------------------------------------------------

describe('POST /v1/users/profile/closure/request — idempotency (T195)', function (): void {

    it('same Idempotency-Key replays 409 without double-emitting ClosureBlocked event', function (): void {
        $userId   = (string) Uuid::uuid7();
        $idempKey = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $headers = [
            'X-Test-User-Id'  => $userId,
            'Idempotency-Key' => $idempKey,
        ];

        $first  = $this->postJson('/v1/users/profile/closure/request', [], $headers);
        $second = $this->postJson('/v1/users/profile/closure/request', [], $headers);

        $first->assertStatus(409);
        $second->assertStatus(409);

        // Only one ClosureBlocked event despite two requests
        $count = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.ClosureBlocked')
            ->where('aggregate_id', $userId)
            ->count();

        expect($count)->toBe(1, 'ClosureBlocked must be emitted exactly once even with duplicate requests');
    });

});

// ---------------------------------------------------------------------------
// T105 — Admin permanent close also blocked by obligation gate
// T196 — Admin close path emits ClosureBlocked event and writes audit
// T197 — closure_obligations written to account_status_history
// ---------------------------------------------------------------------------

describe('POST /v1/admin/users/{user_id}/close — obligation gate (T105/T196/T197)', function (): void {

    it('returns 409 CLOSURE_BLOCKED_BY_OBLIGATIONS even on admin close path', function (): void {
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

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('CLOSURE_BLOCKED_BY_OBLIGATIONS');
        expect($response->json('error.blocked_by'))->toBe(['obligations_check_unavailable']);
    });

    it('account status remains pending_closure when admin close is blocked by stub', function (): void {
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
        )->toBe('pending_closure');
    });

    it('admin without account.close permission is rejected with 403', function (): void {
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
            ],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('admin close without Idempotency-Key returns 422 VALIDATION_ERROR', function (): void {
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
                'X-Test-Permissions' => 'account.close',
            ],
        );

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    // T196 — Admin close path emits Profile.Account.ClosureBlocked
    it('emits Profile.Account.ClosureBlocked to outbox when admin close is blocked by stub', function (): void {
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
            ->where('event_name', 'Profile.Account.ClosureBlocked')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->not->toBeNull('Profile.Account.ClosureBlocked must be written to outbox even on admin close path');
        $payload = json_decode($event->payload, true);
        expect($payload['blocked_by'])->toBe(['obligations_check_unavailable']);
    });

    // T197 — closure_obligations written to account_status_history when user closure blocked
    it('writes history row with closure_obligations populated when user closure is blocked', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        $history = app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId);
        expect($history)->toHaveCount(1);

        $entry = $history->first();
        expect($entry->from_status)->toBe('active');
        expect($entry->to_status)->toBe('active');
        expect($entry->closure_obligations)->toBe(['obligations_check_unavailable']);
    });

    // T197 — closure_obligations written to account_status_history when admin close blocked
    it('writes history row with closure_obligations populated when admin close is blocked', function (): void {
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
        expect($entry->to_status)->toBe('pending_closure');
        expect($entry->closure_obligations)->toBe(['obligations_check_unavailable']);
    });

});

// ---------------------------------------------------------------------------
// T173 — wallet_balance obligation mock test
// ---------------------------------------------------------------------------

describe('Closure blocked by specific wallet_balance obligation (T173)', function (): void {

    it('user closure returns 409 blocked_by=[wallet_balance] and does not transition when E06 mock reports wallet balance', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $walletMock = Mockery::mock(\App\Modules\Profile\Domain\Contracts\FinancialObligationContract::class);
        $walletMock->shouldReceive('checkObligations')
            ->once()
            ->with($userId)
            ->andReturn(new \App\Modules\Profile\Domain\Contracts\ObligationCheckResult(
                hasObligations: true,
                blockedBy:      ['wallet_balance'],
            ));
        app()->instance(\App\Modules\Profile\Domain\Contracts\FinancialObligationContract::class, $walletMock);

        $response = $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('CLOSURE_BLOCKED_BY_OBLIGATIONS');
        expect($response->json('error.blocked_by'))->toBe(['wallet_balance']);

        // Account must remain active — no transition permitted
        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('active', 'Account must remain active when wallet_balance blocks closure');

        // ClosureBlocked event must carry the specific blocked_by reason
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.ClosureBlocked')
            ->where('aggregate_id', $userId)
            ->first();

        expect($event)->not->toBeNull('Profile.Account.ClosureBlocked must be emitted when wallet_balance blocks');
        $payload = json_decode($event->payload, true);
        expect($payload['blocked_by'])->toBe(['wallet_balance']);
    });

    it('admin close on pending_closure returns 409 blocked_by=[wallet_balance] when E06 mock reports wallet balance', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $walletMock = Mockery::mock(\App\Modules\Profile\Domain\Contracts\FinancialObligationContract::class);
        $walletMock->shouldReceive('checkObligations')
            ->once()
            ->with($userId)
            ->andReturn(new \App\Modules\Profile\Domain\Contracts\ObligationCheckResult(
                hasObligations: true,
                blockedBy:      ['wallet_balance'],
            ));
        app()->instance(\App\Modules\Profile\Domain\Contracts\FinancialObligationContract::class, $walletMock);

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

        $response->assertStatus(409);
        expect($response->json('error.code'))->toBe('CLOSURE_BLOCKED_BY_OBLIGATIONS');
        expect($response->json('error.blocked_by'))->toBe(['wallet_balance']);

        // Account must remain pending_closure
        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('pending_closure', 'Account must remain pending_closure when admin close is blocked by wallet_balance');
    });

});
