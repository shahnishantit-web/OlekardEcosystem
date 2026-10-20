<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Phase 14 Service Identity Security Tests — T142
 *
 * Covers:
 * - resolveServiceActor() returns null for user token (actor_type != SERVICE)
 * - resolveActorFromToken() returns null for invalid/expired JWT
 * - resolveActorFromToken() extracts AuthenticatedActor from valid user JWT
 * - resolveServiceActor() extracts ServiceActor from valid service JWT
 * - verifyStepUpScope() returns true for valid step-up token with matching scope
 * - verifyStepUpScope() returns false for wrong scope, invalid/expired token, non-step-up token
 * - revokeSession() marks session revoked in DB and sets Redis key
 */

beforeEach(function (): void {
    $this->app->bind(OtpDeliveryContract::class, fn () => new class implements OtpDeliveryContract {
        public function deliver(string $d, string $otp, string $ch, string $c): OtpDeliveryResult
        {
            return new OtpDeliveryResult(true, 'stub', null);
        }
    });
    Redis::connection('identity')->flushdb();
});

// ============================================================================
// Helpers
// ============================================================================

function svcSecCreateUser(string $mobile, string $fp = 'svc-sec-dev', string $actorType = 'CUSTOMER'): array
{
    $users    = app(UserRepositoryInterface::class);
    $credRepo = app(CredentialRepositoryInterface::class);
    $devRepo  = app(DeviceRepositoryInterface::class);

    $user = $users->create([
        'mobile_number' => $mobile,
        'status'        => 'active',
        'actor_type'    => $actorType,
    ]);
    $credRepo->create([
        'user_id'         => $user->id,
        'credential_type' => 'pin',
        'credential_hash' => Hash::make('999888'),
        'status'          => 'active',
    ]);
    $devRepo->create([
        'user_id'            => $user->id,
        'device_fingerprint' => hash('sha256', $fp),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);

    return ['user' => $user, 'fingerprint' => $fp];
}

function svcSecLogin(mixed $test, string $mobile, string $fp = 'svc-sec-dev'): array
{
    $resp = $test->postJson('/v1/auth/login', [
        'credential_type'    => 'pin',
        'identifier'         => $mobile,
        'credential'         => '999888',
        'device_fingerprint' => $fp,
    ]);
    $resp->assertStatus(200);
    return [
        'access_token' => (string) $resp->json('data.access_token'),
        'session_id'   => (string) $resp->json('data.session_id'),
    ];
}

/**
 * Issue a service token via the API and return the raw JWT string.
 */
function svcSecIssueServiceToken(mixed $test, string $serviceId = 'sec-test-svc'): string
{
    $resp = $test->postJson('/v1/auth/service/token', [
        'service_id' => $serviceId,
        'audience'   => 'test-aud',
        'scopes'     => ['payments:read'],
    ], [
        'X-Service-Secret' => 'test-service-secret',
        'Idempotency-Key'  => (string) Str::uuid(),
    ]);
    $resp->assertStatus(200);
    return (string) $resp->json('data.access_token');
}

/**
 * Issue a step-up token and return the step_up_access_token JWT.
 */
function svcSecIssueStepUpToken(mixed $test, string $mobile, string $fp): string
{
    $auth = svcSecLogin($test, $mobile, $fp);

    $init = $test->postJson('/v1/auth/step-up/initiate', [
        'scope'             => 'payment:high-value',
        'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
    ], ['Authorization' => 'Bearer ' . $auth['access_token']]);
    $init->assertStatus(200);
    expect($init->json('data.step_up_required'))->toBeTrue();

    $verify = $test->postJson('/v1/auth/step-up/verify', [
        'step_up_token' => $init->json('data.step_up_token'),
        'method'        => 'pin',
        'credential'    => '999888',
    ], ['Authorization' => 'Bearer ' . $auth['access_token']]);
    $verify->assertStatus(200);

    return (string) $verify->json('data.step_up_access_token');
}

// ============================================================================
// resolveActorFromToken — user JWT (T142)
// ============================================================================

describe('IdentityModule::resolveActorFromToken (T142)', function (): void {

    it('resolves AuthenticatedActor with correct userId, sessionId, actorType from a valid user JWT', function (): void {
        svcSecCreateUser('+26775001001');
        $auth = svcSecLogin($this, '+26775001001');

        $module = app(IdentityModuleContract::class);
        $actor  = $module->resolveActorFromToken($auth['access_token']);

        expect($actor)->not->toBeNull();
        expect($actor->userId)->not->toBeEmpty();
        expect($actor->sessionId)->toBe($auth['session_id']);
        expect($actor->actorType)->toBe('CUSTOMER');
        expect($actor->roles)->toBeArray();
    });

    it('returns null for a completely invalid (garbage) JWT', function (): void {
        $module = app(IdentityModuleContract::class);
        $actor  = $module->resolveActorFromToken('not-a-jwt-at-all');

        expect($actor)->toBeNull();
    });

    it('returns null for an empty token string', function (): void {
        $module = app(IdentityModuleContract::class);
        expect($module->resolveActorFromToken(''))->toBeNull();
    });

});

// ============================================================================
// resolveServiceActor — service JWT (T142)
// ============================================================================

describe('IdentityModule::resolveServiceActor (T142)', function (): void {

    it('resolves ServiceActor with correct serviceId, actorType, scopes from a valid service JWT', function (): void {
        $serviceJwt = svcSecIssueServiceToken($this, 'verify-svc');

        $module = app(IdentityModuleContract::class);
        $actor  = $module->resolveServiceActor($serviceJwt);

        expect($actor)->not->toBeNull();
        expect($actor->serviceId)->toBe('verify-svc');
        expect($actor->actorType)->toBe('SERVICE');
        expect($actor->scopes)->toContain('payments:read');
        expect($actor->onBehalfOf)->toBeNull();
    });

    it('resolves ServiceActor with on_behalf_of when delegation was requested', function (): void {
        $resp = $this->postJson('/v1/auth/service/token', [
            'service_id'   => 'delegation-svc',
            'audience'     => 'test-aud',
            'scopes'       => ['transfer:initiate'],
            'on_behalf_of' => 'user-delegate-id',
        ], [
            'X-Service-Secret' => 'test-service-secret',
            'Idempotency-Key'  => (string) Str::uuid(),
        ]);
        $resp->assertStatus(200);

        $module = app(IdentityModuleContract::class);
        $actor  = $module->resolveServiceActor((string) $resp->json('data.access_token'));

        expect($actor)->not->toBeNull();
        expect($actor->onBehalfOf)->toBe('user-delegate-id');
    });

    it('returns null for a user JWT (actor_type != SERVICE)', function (): void {
        svcSecCreateUser('+26775002001');
        $auth = svcSecLogin($this, '+26775002001');

        $module = app(IdentityModuleContract::class);
        $actor  = $module->resolveServiceActor($auth['access_token']);

        expect($actor)->toBeNull();
    });

    it('returns null for an invalid JWT', function (): void {
        $module = app(IdentityModuleContract::class);
        expect($module->resolveServiceActor('garbage-token'))->toBeNull();
    });

});

// ============================================================================
// verifyStepUpScope (T142)
// ============================================================================

describe('IdentityModule::verifyStepUpScope (T142)', function (): void {

    it('returns true for a valid step-up token with the matching scope', function (): void {
        svcSecCreateUser('+26775003001');
        $stepUpJwt = svcSecIssueStepUpToken($this, '+26775003001', 'svc-sec-dev');

        $module = app(IdentityModuleContract::class);
        expect($module->verifyStepUpScope($stepUpJwt, 'payment:high-value'))->toBeTrue();
    });

    it('returns false when the required scope does not match the token scope', function (): void {
        svcSecCreateUser('+26775003002');
        $stepUpJwt = svcSecIssueStepUpToken($this, '+26775003002', 'svc-sec-dev');

        $module = app(IdentityModuleContract::class);
        expect($module->verifyStepUpScope($stepUpJwt, 'admin:privileged'))->toBeFalse();
    });

    it('returns false for a non-step-up token (user access token)', function (): void {
        svcSecCreateUser('+26775003003');
        $auth = svcSecLogin($this, '+26775003003');

        $module = app(IdentityModuleContract::class);
        expect($module->verifyStepUpScope($auth['access_token'], 'payment:high-value'))->toBeFalse();
    });

    it('returns false for a non-step-up token (service token)', function (): void {
        $serviceJwt = svcSecIssueServiceToken($this, 'scope-check-svc');

        $module = app(IdentityModuleContract::class);
        expect($module->verifyStepUpScope($serviceJwt, 'payment:high-value'))->toBeFalse();
    });

    it('returns false for an invalid/garbage token', function (): void {
        $module = app(IdentityModuleContract::class);
        expect($module->verifyStepUpScope('not-a-token', 'payment:high-value'))->toBeFalse();
    });

});

// ============================================================================
// revokeSession (T142)
// ============================================================================

describe('IdentityModule::revokeSession (T142)', function (): void {

    it('marks session as revoked in the database', function (): void {
        svcSecCreateUser('+26775004001');
        $auth       = svcSecLogin($this, '+26775004001');
        $sessionId  = $auth['session_id'];

        $module = app(IdentityModuleContract::class);
        $module->revokeSession($sessionId, 'admin-actor-id');

        $session = DB::connection('identity')
            ->table('identity.sessions')
            ->where('id', $sessionId)
            ->first();

        expect($session)->not->toBeNull();
        expect($session->status)->toBe('revoked');
    });

    it('adds the session to the Redis revoked-session cache', function (): void {
        svcSecCreateUser('+26775004002');
        $auth      = svcSecLogin($this, '+26775004002');
        $sessionId = $auth['session_id'];

        $module = app(IdentityModuleContract::class);
        $module->revokeSession($sessionId, 'admin-actor-id');

        $cacheKey = 'identity:revoked:' . $sessionId;
        $cached   = Redis::connection('identity')->get($cacheKey);
        expect($cached)->toBe('1');
    });

    it('does not throw when session does not exist (idempotent noop)', function (): void {
        $module = app(IdentityModuleContract::class);

        expect(fn () => $module->revokeSession((string) Str::uuid(), 'actor-id'))->not->toThrow(\Throwable::class);
    });

});
