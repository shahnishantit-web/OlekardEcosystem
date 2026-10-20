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
// T152 — Account status state-machine serialization under concurrent pressure
// ---------------------------------------------------------------------------

describe('Account status concurrency (T152)', function (): void {

    it('closure request followed by admin suspension: state machine rejects closure because account is no longer active', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        // Admin suspends first
        $suspendResponse = $this->postJson(
            "/v1/admin/users/{$userId}/suspend",
            ['reason' => 'Concurrent test — admin wins race'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.suspend',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );
        $suspendResponse->assertStatus(200);
        expect($suspendResponse->json('data.account_status'))->toBe('suspended');

        // User then tries closure — must fail because account is suspended, not active
        $closureResponse = $this->postJson(
            '/v1/users/profile/closure/request',
            [],
            [
                'X-Test-User-Id'  => $userId,
                'Idempotency-Key' => (string) Uuid::uuid7(),
            ],
        );
        $closureResponse->assertStatus(422);
        expect($closureResponse->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');

        // Final state: suspended (not pending_closure)
        $final = DB::connection('profile')
            ->table('profile.user_profiles')
            ->where('user_id', $userId)
            ->first();
        expect($final->account_status)->toBe('suspended');
    });

    it('concurrent closure cancellation + reactivation with same idempotency key returns same result both times', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'pending_closure',
        ]);

        $idempotencyKey = (string) Uuid::uuid7();
        $headers = [
            'X-Test-User-Id'     => $adminId,
            'X-Test-Actor-Type'  => 'ADMIN',
            'X-Test-Permissions' => 'account.reactivate',
            'Idempotency-Key'    => $idempotencyKey,
        ];

        $first  = $this->postJson("/v1/admin/users/{$userId}/cancel-closure", [], $headers);
        $second = $this->postJson("/v1/admin/users/{$userId}/cancel-closure", [], $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);

        // Both return same account_status
        expect($second->json('data.account_status'))->toBe($first->json('data.account_status'));

        // Exactly one idempotency record for this key
        $recordCount = DB::connection('platform')
            ->table('platform.idempotency_records')
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        expect($recordCount)->toBe(1, 'Exactly one idempotency record must exist; duplicate state write detected');

        // Exactly one status history entry for this transition
        $historyCount = DB::connection('profile')
            ->table('profile.account_status_history')
            ->where('user_id', $userId)
            ->where('from_status', 'pending_closure')
            ->count();
        expect($historyCount)->toBe(1, 'State must transition exactly once regardless of duplicate requests');
    });

    it('admin close followed immediately by admin suspend returns 422 because account is no longer active', function (): void {
        $userId  = (string) Uuid::uuid7();
        $adminId = (string) Uuid::uuid7();

        app(EloquentUserProfileRepository::class)->create([
            'user_id'        => $userId,
            'account_status' => 'active',
        ]);

        // First admin closes the account
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

        // Second admin (or race) tries to suspend — must fail: closed → suspended is invalid
        $suspendResponse = $this->postJson(
            "/v1/admin/users/{$userId}/suspend",
            ['reason' => 'Late race attempt'],
            [
                'X-Test-User-Id'     => $adminId,
                'X-Test-Actor-Type'  => 'ADMIN',
                'X-Test-Permissions' => 'account.suspend',
                'Idempotency-Key'    => (string) Uuid::uuid7(),
            ],
        );
        $suspendResponse->assertStatus(422);
        expect($suspendResponse->json('error.code'))->toBe('INVALID_STATUS_TRANSITION');

        // Final state: closed — no invalid intermediate state
        $final = DB::connection('profile')
            ->table('profile.user_profiles')
            ->where('user_id', $userId)
            ->first();
        expect($final->account_status)->toBe('closed');
    });
});
