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
// T167 — E2E Scenario 4: Suspend → Reactivate
// ---------------------------------------------------------------------------

describe('E2E Scenario 4: Admin Suspend → Reactivate full lifecycle (T167)', function (): void {

    it('admin suspends → GET status shows suspended with reason → admin reactivates → active → history has both transitions', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        // ── Step 1: Admin suspends the account ───────────────────────────────
        $suspendResponse = $this->postJson(
            "/v1/admin/users/{$userId}/suspend",
            ['reason' => 'E2E suspension test reason'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.suspend',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );
        $suspendResponse->assertStatus(200);
        expect($suspendResponse->json('data.account_status'))->toBe('suspended');

        // ── Step 2: GET /v1/users/profile/status reflects suspension ─────────
        $statusAfterSuspend = $this->getJson('/v1/users/profile/status', ['X-Test-User-Id' => $userId]);
        $statusAfterSuspend->assertStatus(200);
        expect($statusAfterSuspend->json('data.account_status'))->toBe('suspended');
        expect($statusAfterSuspend->json('data.history'))->toBeArray();
        expect($statusAfterSuspend->json('data.history'))->toHaveCount(1);

        $suspendEntry = $statusAfterSuspend->json('data.history.0');
        expect($suspendEntry['from_status'])->toBe('active');
        expect($suspendEntry['to_status'])->toBe('suspended');
        expect($suspendEntry['reason'])->toBe('E2E suspension test reason');
        expect($suspendEntry['actor_type'])->toBe('ADMIN');
        // actor_id must be absent on the user-path status endpoint
        expect($suspendEntry)->not->toHaveKey('actor_id', 'actor_id must be hidden on the user-facing status endpoint');

        // Profile.Account.Suspended event in outbox
        $suspendEvent = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.Suspended')
            ->where('aggregate_id', $userId)
            ->first();
        expect($suspendEvent)->not->toBeNull('Profile.Account.Suspended must be in outbox');

        // ── Step 3: Admin reactivates the account ────────────────────────────
        $reactivateResponse = $this->postJson(
            "/v1/admin/users/{$userId}/reactivate",
            ['reason' => 'E2E reactivation test reason'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.reactivate',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );
        $reactivateResponse->assertStatus(200);
        expect($reactivateResponse->json('data.account_status'))->toBe('active');

        // ── Step 4: GET /v1/users/profile/status reflects reactivation ───────
        $statusAfterReactivate = $this->getJson('/v1/users/profile/status', ['X-Test-User-Id' => $userId]);
        $statusAfterReactivate->assertStatus(200);
        expect($statusAfterReactivate->json('data.account_status'))->toBe('active');

        $history = $statusAfterReactivate->json('data.history');
        expect($history)->toHaveCount(2, 'History must contain both suspension and reactivation entries');

        // History is returned DESC (most recent first)
        expect($history[0]['from_status'])->toBe('suspended');
        expect($history[0]['to_status'])->toBe('active');
        expect($history[1]['from_status'])->toBe('active');
        expect($history[1]['to_status'])->toBe('suspended');

        // Profile.Account.Reactivated event in outbox
        $reactivateEvent = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.Reactivated')
            ->where('aggregate_id', $userId)
            ->first();
        expect($reactivateEvent)->not->toBeNull('Profile.Account.Reactivated must be in outbox');
    });
});

// ---------------------------------------------------------------------------
// T168 — E2E Scenario 5: Closure + Cancel + Close
// ---------------------------------------------------------------------------

describe('E2E Scenario 5: Closure → Cancel → Close full lifecycle (T168)', function (): void {

    it('stub blocks → mock allows → pending_closure → user cancels → active → admin closes → closed', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        // ── Step 1: Default obligation stub blocks closure ───────────────────
        $blockedResponse = $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );
        $blockedResponse->assertStatus(409);
        expect($blockedResponse->json('error.code'))->toBe('CLOSURE_BLOCKED_BY_OBLIGATIONS');
        expect($blockedResponse->json('error.blocked_by'))->toBe(['obligations_check_unavailable']);

        // Account must remain active after blocked attempt
        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('active');

        // ── Step 2: Override with mock E06 returning zero obligations ────────
        $noObligationsMock = Mockery::mock(FinancialObligationContract::class);
        $noObligationsMock->shouldReceive('checkObligations')
            ->andReturn(new ObligationCheckResult(hasObligations: false, blockedBy: []));
        app()->instance(FinancialObligationContract::class, $noObligationsMock);

        // ── Step 3: Request closure succeeds → pending_closure ───────────────
        $closureResponse = $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );
        $closureResponse->assertStatus(200);
        expect($closureResponse->json('data.account_status'))->toBe('pending_closure');

        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('pending_closure');

        $closureRequestedEvent = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.ClosureRequested')
            ->where('aggregate_id', $userId)
            ->first();
        expect($closureRequestedEvent)->not->toBeNull('Profile.Account.ClosureRequested must be in outbox');

        // ── Step 4: User self-cancels → back to active ───────────────────────
        $cancelResponse = $this->deleteJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );
        $cancelResponse->assertStatus(200);
        expect($cancelResponse->json('data.account_status'))->toBe('active');

        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('active');

        $closureCancelledEvent = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.ClosureCancelled')
            ->where('aggregate_id', $userId)
            ->first();
        expect($closureCancelledEvent)->not->toBeNull('Profile.Account.ClosureCancelled must be in outbox');

        // ── Step 5: Re-request closure → pending_closure again ───────────────
        $closureResponse2 = $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );
        $closureResponse2->assertStatus(200);
        expect($closureResponse2->json('data.account_status'))->toBe('pending_closure');

        // ── Step 6: Admin permanently closes the account → closed ─────────────
        $closeResponse = $this->postJson(
            "/v1/admin/users/{$userId}/close",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.close',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );
        $closeResponse->assertStatus(200);
        expect($closeResponse->json('data.account_status'))->toBe('closed');

        // Final state: account is permanently closed in the database
        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('closed');

        $closedEvent = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.Closed')
            ->where('aggregate_id', $userId)
            ->first();
        expect($closedEvent)->not->toBeNull('Profile.Account.Closed must be in outbox after admin permanent closure');

        // History must record the full lifecycle: request → cancel → re-request → close
        $historyRepo = app(EloquentAccountStatusHistoryRepository::class);
        $history     = $historyRepo->getHistoryForUser($userId);
        expect($history->count())->toBeGreaterThanOrEqual(3, 'History must contain at least: closure-request, cancel, final-close transitions');

        // Final history entry must be the permanent closure
        $latestEntry = $history->first(); // getHistoryForUser returns DESC
        expect($latestEntry->to_status)->toBe('closed');
        expect($latestEntry->actor_type)->toBe('ADMIN');
    });
});
