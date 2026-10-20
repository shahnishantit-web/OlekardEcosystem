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
// T091 — Happy path: Suspend
// ---------------------------------------------------------------------------

describe('POST /v1/admin/users/{user_id}/suspend (T091)', function (): void {

    it('suspends an active account, emits Profile.Account.Suspended event, and writes history', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/suspend",
            ['reason' => 'Abuse of platform terms.'],
            [
                'X-Test-User-Id'      => $adminId,
                'X-Test-Actor-Type'   => 'ADMIN',
                'X-Test-Permissions'  => 'account.suspend',
                'Idempotency-Key'     => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.account_status'))->toBe('suspended');

        // DB: profile updated
        $profile = app(EloquentUserProfileRepository::class)->findByUserId($userId);
        expect($profile->account_status)->toBe('suspended');

        // DB: history entry created
        $history = app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId);
        expect($history)->toHaveCount(1);
        $entry = $history->first();
        expect($entry->from_status)->toBe('active');
        expect($entry->to_status)->toBe('suspended');
        expect($entry->reason)->toBe('Abuse of platform terms.');
        expect($entry->actor_id)->toBe($adminId);
        expect($entry->actor_type)->toBe('ADMIN');

        // Outbox: Profile.Account.Suspended emitted
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.Suspended')
            ->where('aggregate_id', $userId)
            ->first();
        expect($event)->not->toBeNull('Profile.Account.Suspended must be written to outbox');
        $payload = json_decode($event->payload, true);
        expect($payload['user_id'])->toBe($userId);
        expect($payload['reason'])->toBe('Abuse of platform terms.');
    });

    it('suspend audit records both admin actor_id and subject_user_id', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $auditService = Mockery::mock(AuditServiceInterface::class);
        $auditService->shouldReceive('record')
            ->once()
            ->withArgs(function (AuditEventData $data) use ($adminId, $userId): bool {
                return $data->action === 'profile.account.suspended'
                    && $data->actorId === $adminId
                    && $data->actorType === 'ADMIN'
                    && $data->resourceId === $userId
                    && ($data->metadata['subject_user_id'] ?? null) === $userId
                    && $data->outcome === 'success';
            });
        app()->instance(AuditServiceInterface::class, $auditService);

        $this->postJson(
            "/v1/admin/users/{$userId}/suspend",
            ['reason' => 'Fraud suspicion.'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.suspend',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );
    });

    it('is idempotent: repeated Idempotency-Key replays 200 without double-write', function (): void {
        $userId       = (string) Uuid::uuid7();
        $adminId      = (string) Uuid::uuid7();
        $idempKey     = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $headers = [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'ADMIN',
            'X-Test-Permissions' => 'account.suspend',
            'Idempotency-Key'    => $idempKey,
        ];

        $first  = $this->postJson("/v1/admin/users/{$userId}/suspend", ['reason' => 'Test.'], $headers);
        $second = $this->postJson("/v1/admin/users/{$userId}/suspend", ['reason' => 'Test.'], $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);

        // Only one history entry despite two requests
        expect(
            app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId)
        )->toHaveCount(1);

        // Only one outbox event
        $count = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.Suspended')
            ->where('aggregate_id', $userId)
            ->count();
        expect($count)->toBe(1);
    });

});

// ---------------------------------------------------------------------------
// T091 — Happy path: Reactivate
// ---------------------------------------------------------------------------

describe('POST /v1/admin/users/{user_id}/reactivate (T091)', function (): void {

    it('reactivates a suspended account, emits Profile.Account.Reactivated event, and writes history', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'suspended']);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/reactivate",
            ['reason' => 'Issue resolved.'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.reactivate',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(200);
        expect($response->json('success'))->toBeTrue();
        expect($response->json('data.account_status'))->toBe('active');

        // DB: profile updated
        expect(
            app(EloquentUserProfileRepository::class)->findByUserId($userId)->account_status
        )->toBe('active');

        // DB: history entry created
        $history = app(EloquentAccountStatusHistoryRepository::class)->getHistoryForUser($userId);
        expect($history)->toHaveCount(1);
        $entry = $history->first();
        expect($entry->from_status)->toBe('suspended');
        expect($entry->to_status)->toBe('active');
        expect($entry->reason)->toBe('Issue resolved.');
        expect($entry->actor_id)->toBe($adminId);

        // Outbox: Profile.Account.Reactivated emitted
        $event = DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Profile.Account.Reactivated')
            ->where('aggregate_id', $userId)
            ->first();
        expect($event)->not->toBeNull('Profile.Account.Reactivated must be written to outbox');
    });

    it('reactivate audit records both admin actor_id and subject_user_id', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'suspended']);

        $auditService = Mockery::mock(AuditServiceInterface::class);
        $auditService->shouldReceive('record')
            ->once()
            ->withArgs(function (AuditEventData $data) use ($adminId, $userId): bool {
                return $data->action === 'profile.account.reactivated'
                    && $data->actorId === $adminId
                    && $data->actorType === 'ADMIN'
                    && $data->resourceId === $userId
                    && ($data->metadata['subject_user_id'] ?? null) === $userId
                    && $data->outcome === 'success';
            });
        app()->instance(AuditServiceInterface::class, $auditService);

        $this->postJson(
            "/v1/admin/users/{$userId}/reactivate",
            [],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.reactivate',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );
    });

    it('history shows suspend then reactivate in descending order', function (): void {
        $userId      = (string) Uuid::uuid7();
        $adminId     = (string) Uuid::uuid7();
        $suspendKey  = (string) Uuid::uuid7();
        $reactiveKey = (string) Uuid::uuid7();
        $headers     = ['X-Test-User-Id' => $adminId, 'X-Test-Actor-Type' => 'ADMIN'];

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $this->postJson("/v1/admin/users/{$userId}/suspend", ['reason' => 'Test suspend.'], [
            ...$headers,
            'X-Test-Permissions' => 'account.suspend',
            'Idempotency-Key'    => $suspendKey,
        ])->assertStatus(200);

        $this->postJson("/v1/admin/users/{$userId}/reactivate", [], [
            ...$headers,
            'X-Test-Permissions' => 'account.reactivate',
            'Idempotency-Key'    => $reactiveKey,
        ])->assertStatus(200);

        $statusResponse = $this->getJson(
            "/v1/admin/users/{$userId}/profile/status",
            ['X-Test-User-Id' => $adminId, 'X-Test-Permissions' => 'profile.read'],
        );

        $statusResponse->assertStatus(200);
        $history = $statusResponse->json('data.history');
        expect($history)->toHaveCount(2);
        // Reactivation (most recent) appears first
        expect($history[0]['to_status'])->toBe('active');
        expect($history[1]['to_status'])->toBe('suspended');
    });

});

// ---------------------------------------------------------------------------
// T092 — Invalid transitions
// ---------------------------------------------------------------------------

describe('Invalid status transitions (T092)', function (): void {

    it('suspend already-suspended account returns 400 INVALID_STATUS_TRANSITION', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'suspended']);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/suspend",
            ['reason' => 'Already suspended.'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.suspend',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');
    });

    it('reactivate already-active account returns 400 INVALID_STATUS_TRANSITION', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/reactivate",
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

    it('suspend without account.suspend permission returns 403 FORBIDDEN', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/suspend",
            ['reason' => 'Should be blocked.'],
            [
                'X-Test-User-Id'  => $adminId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
                // No X-Test-Permissions header → no account.suspend permission
            ],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('reactivate without account.reactivate permission returns 403 FORBIDDEN', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'suspended']);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/reactivate",
            [],
            [
                'X-Test-User-Id'  => $adminId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
                // No account.reactivate permission
            ],
        );

        $response->assertStatus(403);
        expect($response->json('error.code'))->toBe('FORBIDDEN');
    });

    it('suspend without reason returns 400 VALIDATION_ERROR', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create(['user_id' => $userId, 'account_status' => 'active']);

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/suspend",
            [], // no reason
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.suspend',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(422);
        expect($response->json('error.code'))->toBe('VALIDATION_ERROR');
    });

    it('suspend non-existent profile returns 404 PROFILE_NOT_FOUND', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        $response = $this->postJson(
            "/v1/admin/users/{$userId}/suspend",
            ['reason' => 'No profile.'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.suspend',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );

        $response->assertStatus(404);
        expect($response->json('error.code'))->toBe('PROFILE_NOT_FOUND');
    });

    it('suspend returns 401 when no auth token provided', function (): void {
        $userId = (string) Uuid::uuid7();
        $response = $this->postJson("/v1/admin/users/{$userId}/suspend", ['reason' => 'Test.']);
        $response->assertStatus(401);
    });

});
