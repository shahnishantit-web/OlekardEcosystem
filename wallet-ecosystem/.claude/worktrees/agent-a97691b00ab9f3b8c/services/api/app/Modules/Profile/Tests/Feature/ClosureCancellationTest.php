<?php

declare(strict_types=1);

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
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
// T099 — Happy path: User self-service cancel closure
// ---------------------------------------------------------------------------

describe('DELETE /v1/users/profile/closure/request (T099)', function (): void {

    it('cancels pending closure, transitions to active, emits ClosureCancelled event, and writes history', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $response = $this->deleteJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.account_status'))->toBe('active');

        // DB: profile transitioned to active
        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('active');

        // DB: history record with actor_type = USER
        $history = app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId);
        expect($history)->toHaveCount(1);
        $entry = $history->first();
        expect($entry->from_status)->toBe('pending_closure');
        expect($entry->to_status)->toBe('active');
        expect($entry->actor_id)->toBe($userId);
        expect($entry->actor_type)->toBe('USER');

        // Outbox: Profile.Account.ClosureCancelled emitted
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.ClosureCancelled')
            ->where('aggregate_id', $userId)
            ->first();
        expect($event)->not->toBeNull('Profile.Account.ClosureCancelled must be written to outbox');

        $payload = json_decode($event->payload, true);
        expect($payload['user_id'])->toBe($userId);
        expect($payload['actor_type'])->toBe('USER');
    });

    it('cancel closure audit records profile.account.closure-cancelled with actorId = userId and actorType = USER', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $auditService = Mockery::mock(AuditServiceInterface::class);
        $auditService->shouldReceive('record')
            ->once()
            ->withArgs(function (AuditEventData $data) use ($userId): bool {
                return $data->action === 'profile.account.closure-cancelled'
                    && $data->actorId === $userId
                    && $data->actorType === 'USER'
                    && $data->resourceId === $userId
                    && ($data->metadata['subject_user_id'] ?? null) === $userId
                    && $data->outcome === 'success';
            });
        app()->instance(AuditServiceInterface::class, $auditService);

        $this->deleteJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );
    });

    it('is idempotent: same Idempotency-Key replays 200 without double-write', function (): void {
        $userId   = (string) Uuid::uuid7();
        $idempKey = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $headers = [
            'X-Test-User-Id'  => $userId,
            'Idempotency-Key' => $idempKey,
        ];

        $first  = $this->deleteJson('/v1/users/profile/closure/request', [], $headers);
        $second = $this->deleteJson('/v1/users/profile/closure/request', [], $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);

        // Only one history entry despite two requests
        expect(
            app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId)
        )->toHaveCount(1);

        // Only one outbox event
        $count = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.ClosureCancelled')
            ->where('aggregate_id', $userId)
            ->count();
        expect($count)->toBe(1);
    });

});

// ---------------------------------------------------------------------------
// T099 — POST /v1/users/profile/closure/request blocked by stub
// ---------------------------------------------------------------------------

describe('POST /v1/users/profile/closure/request (T099 — stub blocks)', function (): void {

    it('returns 409 CLOSURE_BLOCKED_BY_OBLIGATIONS because stub always blocks, and account status is unchanged', function (): void {
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
        expect($response->json('error.blocked_by'))->toBe(['obligations_check_unavailable']);

        // Account status must be unchanged
        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('active');
    });

    it('emits Profile.Account.ClosureBlocked event when closure is blocked', function (): void {
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
        expect($event)->not->toBeNull('Profile.Account.ClosureBlocked must be written to outbox');

        $payload = json_decode($event->payload, true);
        expect($payload['user_id'])->toBe($userId);
        expect($payload['blocked_by'])->toBe(['obligations_check_unavailable']);
    });

});

// ---------------------------------------------------------------------------
// T100 — Invalid transitions and permission tests
// ---------------------------------------------------------------------------

describe('Invalid transitions (T100)', function (): void {

    it('cancel closure on active account returns 400 INVALID_STATUS_TRANSITION', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        $response = $this->deleteJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');
    });

    it('cancel closure on suspended account returns 400 INVALID_STATUS_TRANSITION', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'suspended',
        ]);

        $response = $this->deleteJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');
    });

    it('cancel closure without Idempotency-Key returns 422 VALIDATION_ERROR', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $response = $this->deleteJson(
            '/v1/users/profile/closure/request',
            [],
            ['X-Test-User-Id' => $userId], // no Idempotency-Key
        );

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('cancel closure returns 401 when unauthenticated', function (): void {
        $response = $this->deleteJson('/v1/users/profile/closure/request');
        $response->assertStatus(401);
    });

});

// ---------------------------------------------------------------------------
// T100 — Admin cancel-closure permission tests
// ---------------------------------------------------------------------------

describe('POST /v1/admin/users/{user_id}/cancel-closure (T100)', function (): void {

    it('admin with account.reactivate permission cancels pending closure and emits ClosureCancelled', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/cancel-closure",
            ['reason' => 'User reconsidered.'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.reactivate',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(200);
        expect($response->json('data.account_status'))->toBe('active');

        // DB: history record with actor_type = ADMIN
        $history = app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId);
        expect($history)->toHaveCount(1);
        $entry = $history->first();
        expect($entry->actor_type)->toBe('ADMIN');
        expect($entry->actor_id)->toBe($adminId);
        expect($entry->reason)->toBe('User reconsidered.');

        // Outbox: Profile.Account.ClosureCancelled with actor_type = ADMIN
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.ClosureCancelled')
            ->where('aggregate_id', $userId)
            ->first();
        expect($event)->not->toBeNull();

        $payload = json_decode($event->payload, true);
        expect($payload['actor_type'])->toBe('ADMIN');
    });

    it('admin cancel-closure without account.reactivate permission returns 403 FORBIDDEN', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/cancel-closure",
            [],
            [
                'X-Test-User-Id'  => $adminId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
                // No X-Test-Permissions → no account.reactivate permission
            ],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('admin cancel-closure without Idempotency-Key returns 422 VALIDATION_ERROR', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/cancel-closure",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Permissions' => 'account.reactivate',
                // No Idempotency-Key
            ],
        );

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('admin cancel-closure on non-pending_closure account returns 400 INVALID_STATUS_TRANSITION', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active', // not pending_closure
        ]);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/cancel-closure",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.reactivate',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');
    });

    it('admin cancel-closure is idempotent: same Idempotency-Key replays 200 without double-write', function (): void {
        $userId     = (string) Uuid::uuid7();
        $adminId    = (string) Uuid::uuid7();
        $idempKey   = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $headers = [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'ADMIN',
            'X-Test-Permissions' => 'account.reactivate',
            'Idempotency-Key'    => $idempKey,
        ];

        $first  = $this->postJson("/v1/admin/users/{$userId}/cancel-closure", [], $headers);
        $second = $this->postJson("/v1/admin/users/{$userId}/cancel-closure", [], $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);

        // Only one history entry despite two requests
        expect(
            app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId)
        )->toHaveCount(1);

        // Only one outbox event
        $count = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.ClosureCancelled')
            ->where('aggregate_id', $userId)
            ->count();
        expect($count)->toBe(1);
    });

});

// ---------------------------------------------------------------------------
// T194 — Optional reason body field passthrough on user self-service cancel
// ---------------------------------------------------------------------------

describe('Reason passthrough on DELETE /v1/users/profile/closure/request (T194)', function (): void {

    it('optional reason body is stored in history record when provided by user', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $this->deleteJson(
            '/v1/users/profile/closure/request',
            ['reason' => 'Changed my mind.'],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        )->assertStatus(200);

        $history = app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId);
        expect($history->first()->reason)->toBe('Changed my mind.');
    });

    it('null reason when no body field provided', function (): void {
        $userId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $this->deleteJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        )->assertStatus(200);

        $history = app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId);
        expect($history->first()->reason)->toBeNull();
    });

});
