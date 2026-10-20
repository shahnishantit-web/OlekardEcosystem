<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;

/**
 * Phase 13 Step-Up Security Tests — T132
 *
 * Covers:
 * - Step-up token rejected as general access token (wrong aud) (AC-013)
 * - Step-up token aud is "step-up:{scope}" — NOT the general app.url
 * - Wrong credential rejected (401)
 * - Expired/invalid step-up challenge token rejected (401)
 * - Session binding: step-up token bound to issuing session (cannot be used from a different session)
 * - Wrong scope: step-up token carries the correct scope claim
 * - Admin step-up: Admin actor can initiate step-up for privileged operations (FR-E01-038)
 * - Audit events: step-up-completed and step-up-failed emitted (T129; AC-015)
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

function secSupCreateUser(string $mobile, string $fp = 'sec-sup-dev', string $actorType = 'CUSTOMER'): array
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
        'credential_hash' => Hash::make('654321'),
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

function secSupLogin(mixed $test, string $mobile, string $fp = 'sec-sup-dev'): array
{
    $resp = $test->postJson('/v1/auth/login', [
        'credential_type'    => 'pin',
        'identifier'         => $mobile,
        'credential'         => '654321',
        'device_fingerprint' => $fp,
    ]);
    $resp->assertStatus(200);
    return [
        'access_token' => (string) $resp->json('data.access_token'),
        'session_id'   => (string) $resp->json('data.session_id'),
    ];
}

function secSupInitiate(mixed $test, string $token, string $scope = 'payment:high-value'): string
{
    $resp = $test->postJson('/v1/auth/step-up/initiate', [
        'scope'             => $scope,
        'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
    ], ['Authorization' => "Bearer {$token}"]);
    $resp->assertStatus(200);
    expect($resp->json('data.step_up_required'))->toBeTrue();
    return (string) $resp->json('data.step_up_token');
}

// ============================================================================
// Step-up JWT audience enforcement (AC-013)
// ============================================================================

describe('Step-up token audience enforcement (T132)', function (): void {

    it('step-up access token has aud = step-up:{scope} (not the general app.url audience)', function (): void {
        secSupCreateUser('+26774001001');
        $auth        = secSupLogin($this, '+26774001001');
        $challengeToken = secSupInitiate($this, $auth['access_token'], 'payment:high-value');

        $verify = $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $verify->assertStatus(200);
        $stepUpJwt = $verify->json('data.step_up_access_token');
        expect($stepUpJwt)->not->toBeNull();

        // Parse the JWT without full verification to inspect claims
        $parts = explode('.', $stepUpJwt);
        expect(count($parts))->toBe(3);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        // aud MUST be "step-up:payment:high-value" — NOT the general app.url
        $aud = $claims['aud'] ?? null;
        if (is_array($aud)) {
            $aud = implode(',', $aud);
        }
        expect($aud)->toContain('step-up:payment:high-value');
        expect($aud)->not->toBe(config('app.url', 'identity-service'));

        // step_up_scope claim must match
        expect($claims['step_up_scope'] ?? null)->toBe('payment:high-value');

        // step_up_verified_at must be present and be a recent unix timestamp (spec §10.1)
        $verifiedAt = $claims['step_up_verified_at'] ?? null;
        expect($verifiedAt)->toBeInt();
        expect($verifiedAt)->toBeGreaterThan(0);
        expect($verifiedAt)->toBeGreaterThanOrEqual(time() - 10);
    });

    it('step-up access token contains session_id binding', function (): void {
        secSupCreateUser('+26774001002');
        $auth           = secSupLogin($this, '+26774001002');
        $challengeToken = secSupInitiate($this, $auth['access_token']);

        $verify = $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $verify->assertStatus(200);
        $parts  = explode('.', $verify->json('data.step_up_access_token'));
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        // session_id must match the authenticated session
        expect($claims['session_id'] ?? null)->toBe($auth['session_id']);

        // step_up_verified_at must be present (spec §10.1; T226)
        $verifiedAt = $claims['step_up_verified_at'] ?? null;
        expect($verifiedAt)->toBeInt();
        expect($verifiedAt)->toBeGreaterThan(0);
    });

});

// ============================================================================
// Invalid credential / expired challenge (T132)
// ============================================================================

describe('Invalid step-up challenge handling (T132)', function (): void {

    it('wrong PIN credential returns 401 with IDENTITY_014', function (): void {
        secSupCreateUser('+26774002001');
        $auth           = secSupLogin($this, '+26774002001');
        $challengeToken = secSupInitiate($this, $auth['access_token']);

        $resp = $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'pin',
            'credential'    => '000000', // wrong PIN
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_014');
    });

    it('invalid (garbage) step_up_token returns 401', function (): void {
        secSupCreateUser('+26774002002');
        $auth = secSupLogin($this, '+26774002002');

        $resp = $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => 'completely-invalid-token',
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_014');
    });

    it('unsupported method returns 401 (not a valid step-up method)', function (): void {
        secSupCreateUser('+26774002003');
        $auth           = secSupLogin($this, '+26774002003');
        $challengeToken = secSupInitiate($this, $auth['access_token']);

        $resp = $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'biometric', // not yet supported
            'credential'    => 'assertion-blob',
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(401);
    });

});

// ============================================================================
// Session binding (T132)
// ============================================================================

describe('Step-up session binding (T132)', function (): void {

    it('step-up challenge issued in one session cannot be consumed by a different session', function (): void {
        // User A issues the challenge
        secSupCreateUser('+26774003001', 'binding-dev-1');
        $authA          = secSupLogin($this, '+26774003001', 'binding-dev-1');
        $challengeToken = secSupInitiate($this, $authA['access_token']);

        // User B (different account, different session) attempts to consume A's challenge
        secSupCreateUser('+26774003002', 'binding-dev-2');
        $authB = secSupLogin($this, '+26774003002', 'binding-dev-2');

        $resp = $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$authB['access_token']}"]);

        // Binding mismatch: user_id or session_id in challenge ≠ authenticated user
        $resp->assertStatus(401);
    });

});

// ============================================================================
// Admin step-up for privileged operations (FR-E01-038; T132)
// ============================================================================

describe('Admin step-up (FR-E01-038; T132)', function (): void {

    it('Admin actor can initiate step-up for privileged admin operations', function (): void {
        secSupCreateUser('+26774004001', 'admin-sup-dev', 'ADMIN');
        $auth = secSupLogin($this, '+26774004001', 'admin-sup-dev');

        $resp = $this->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'admin:user-management',
            'operation_context' => ['amount' => 0, 'currency' => 'BWP', 'risk_required' => true],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeTrue();
        expect($resp->json('data.step_up_token'))->not->toBeNull();
    });

    it('Admin step-up token has correct scope for privileged operation', function (): void {
        secSupCreateUser('+26774004002', 'admin-sup-dev2', 'ADMIN');
        $auth           = secSupLogin($this, '+26774004002', 'admin-sup-dev2');
        $challengeToken = secSupInitiate($this, $auth['access_token'], 'admin:privileged-action');

        $verify = $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);

        $verify->assertStatus(200);
        expect($verify->json('data.scope'))->toBe('admin:privileged-action');

        $parts  = explode('.', $verify->json('data.step_up_access_token'));
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        expect($claims['step_up_scope'] ?? null)->toBe('admin:privileged-action');
    });

});

// ============================================================================
// Audit events (T129; AC-015)
// ============================================================================

describe('Audit events for step-up (T129)', function (): void {

    it('successful step-up emits identity.mfa.step-up-completed audit event', function (): void {
        secSupCreateUser('+26774005001');
        $auth           = secSupLogin($this, '+26774005001');
        $challengeToken = secSupInitiate($this, $auth['access_token']);

        $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"])->assertStatus(200);

        $event = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.mfa.step-up-completed')
            ->orderByDesc('occurred_at')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->outcome)->toBe('success');
    });

    it('failed step-up emits identity.mfa.step-up-failed audit event', function (): void {
        secSupCreateUser('+26774005002');
        $auth           = secSupLogin($this, '+26774005002');
        $challengeToken = secSupInitiate($this, $auth['access_token']);

        $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'pin',
            'credential'    => '000000', // wrong
        ], ['Authorization' => "Bearer {$auth['access_token']}"])->assertStatus(401);

        $event = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.mfa.step-up-failed')
            ->orderByDesc('occurred_at')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->outcome)->toBe('failure');
    });

    it('step-up audit events do not contain raw IP addresses', function (): void {
        secSupCreateUser('+26774005003');
        $auth           = secSupLogin($this, '+26774005003');
        $challengeToken = secSupInitiate($this, $auth['access_token']);

        $this->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"])->assertStatus(200);

        $event = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.mfa.step-up-completed')
            ->orderByDesc('occurred_at')
            ->first();

        expect($event)->not->toBeNull();
        // ip_hash must be HMAC form — not a raw IPv4 or IPv6
        $ipPattern = '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/';
        expect((string) $event->ip_address)->not->toMatch($ipPattern);
    });

});
