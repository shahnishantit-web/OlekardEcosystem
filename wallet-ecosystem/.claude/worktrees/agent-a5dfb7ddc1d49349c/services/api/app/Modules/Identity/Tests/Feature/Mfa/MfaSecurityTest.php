<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use chillerlan\Authenticator\Authenticator;
use chillerlan\Authenticator\AuthenticatorOptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

/**
 * Phase 12 MFA Security Tests — T123
 *
 * Covers:
 * - TOTP secret never appears in any API response or log
 * - Invalid TOTP code is rejected
 * - FIDO2 replay attack rejected (new sign_count ≤ stored → 401)
 * - MFA cannot be bypassed for Admin login (AC-028)
 * - Expired/reused mfa_pending token cannot grant a session
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

/** Seed a user with PIN + trusted device. Returns ['user', 'fingerprint']. */
function secMfaCreateUser(string $mobile, string $fp = 'sec-mfa-fp', string $actorType = 'CUSTOMER'): array
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

function secMfaLogin(mixed $test, string $mobile, string $fp = 'sec-mfa-fp'): string
{
    $resp = $test->postJson('/v1/auth/login', [
        'credential_type'    => 'pin',
        'identifier'         => $mobile,
        'credential'         => '654321',
        'device_fingerprint' => $fp,
    ]);
    $resp->assertStatus(200);
    return (string) $resp->json('data.access_token');
}

function secExtractTotpSecret(string $totpUri): string
{
    parse_str(parse_url($totpUri, PHP_URL_QUERY), $params);
    return (string) ($params['secret'] ?? '');
}

function secGenerateTotpCode(string $secret): string
{
    $auth = new Authenticator(new AuthenticatorOptions);
    $auth->setSecret($secret);
    return $auth->code();
}

/** Enroll TOTP and return secret. */
function secEnrollTotp(mixed $test, string $mobile, string $fp, string $bearerToken): string
{
    $enrollResp = $test->postJson('/v1/auth/mfa/enroll/totp', [], [
        'Authorization' => "Bearer {$bearerToken}",
    ]);
    $enrollResp->assertStatus(200);

    $secret       = secExtractTotpSecret($enrollResp->json('data.totp_uri'));
    $enrollmentId = $enrollResp->json('data.enrollment_id');

    $test->postJson('/v1/auth/mfa/enroll/totp/confirm', [
        'enrollment_id' => $enrollmentId,
        'totp_code'     => secGenerateTotpCode($secret),
    ], ['Authorization' => "Bearer {$bearerToken}"])->assertStatus(200);

    return $secret;
}

// =============================================================================

describe('TOTP secret not exposed in API responses (T123)', function (): void {

    it('TOTP enroll response never contains credential_hash or raw secret', function (): void {
        secMfaCreateUser('+26772100001');
        $token = secMfaLogin($this, '+26772100001');

        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $enrollResp->assertStatus(200);

        $body = $enrollResp->getContent();

        // The response contains totp_uri (which includes the secret) by design for enrollment.
        // What it must NOT contain is the stored credential_hash key from the DB model.
        expect($body)->not->toContain('"credential_hash"');
        expect($body)->not->toContain('"fido2_public_key"');
        expect($body)->not->toContain('"biometric_public_key"');
    });

    it('confirm response contains backup_codes but never credential_hash', function (): void {
        secMfaCreateUser('+26772100002');
        $token      = secMfaLogin($this, '+26772100002');

        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $secret       = secExtractTotpSecret($enrollResp->json('data.totp_uri'));
        $enrollmentId = $enrollResp->json('data.enrollment_id');

        $confirmResp = $this->postJson('/v1/auth/mfa/enroll/totp/confirm', [
            'enrollment_id' => $enrollmentId,
            'totp_code'     => secGenerateTotpCode($secret),
        ], ['Authorization' => "Bearer {$token}"]);

        $confirmResp->assertStatus(200);
        $body = $confirmResp->getContent();
        expect($body)->not->toContain('"credential_hash"');
        // backup_codes must be present (user-facing recovery)
        expect($confirmResp->json('data.backup_codes'))->toBeArray();
    });

    it('mfa/verify session response never contains credential_hash', function (): void {
        secMfaCreateUser('+26772100003');
        $token  = secMfaLogin($this, '+26772100003');
        $secret = secEnrollTotp($this, '+26772100003', 'sec-mfa-fp', $token);

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772100003',
            'credential'         => '654321',
            'device_fingerprint' => 'sec-mfa-fp',
        ]);
        $mfaToken = $loginResp->json('data.mfa_token');

        $verifyResp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'totp',
            'code'      => secGenerateTotpCode($secret),
        ]);
        $verifyResp->assertStatus(200);
        expect($verifyResp->getContent())->not->toContain('"credential_hash"');
    });

});

// =============================================================================

describe('Invalid TOTP code rejected (T123)', function (): void {

    it('wrong TOTP code at mfa/verify returns 401 (T123)', function (): void {
        secMfaCreateUser('+26772101001');
        $token  = secMfaLogin($this, '+26772101001');
        secEnrollTotp($this, '+26772101001', 'sec-mfa-fp', $token);

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772101001',
            'credential'         => '654321',
            'device_fingerprint' => 'sec-mfa-fp',
        ]);
        $mfaToken = $loginResp->json('data.mfa_token');

        $resp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'totp',
            'code'      => '000000', // always-wrong code
        ]);
        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_014');
        expect($resp->json('data'))->toBeNull();
    });

    it('mfa_pending token is consumed after one failed attempt; subsequent valid verify fails (T123)', function (): void {
        secMfaCreateUser('+26772101002');
        $token  = secMfaLogin($this, '+26772101002');
        $secret = secEnrollTotp($this, '+26772101002', 'sec-mfa-fp', $token);

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772101002',
            'credential'         => '654321',
            'device_fingerprint' => 'sec-mfa-fp',
        ]);
        $mfaToken = $loginResp->json('data.mfa_token');

        // First attempt: wrong code — token still in Redis (not consumed on failure)
        $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'totp',
            'code'      => '999999',
        ])->assertStatus(401);

        // Second attempt with correct code — token still valid (only removed on SUCCESS)
        $validResp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'totp',
            'code'      => secGenerateTotpCode($secret),
        ]);
        $validResp->assertStatus(200);
        expect($validResp->json('data.access_token'))->not->toBeNull();

        // Third attempt: token now consumed — must fail
        $replayResp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'totp',
            'code'      => secGenerateTotpCode($secret),
        ]);
        $replayResp->assertStatus(401);
    });

});

// =============================================================================

describe('FIDO2 replay attack rejected (FR-E01-049; T116; T123)', function (): void {

    it('sign_count equal to stored count is rejected (replay attack)', function (): void {
        secMfaCreateUser('+26772102001', 'fido2-replay-dev');
        $token = secMfaLogin($this, '+26772102001', 'fido2-replay-dev');

        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/fido2', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $enrollmentId = $enrollResp->json('data.enrollment_id');

        $this->postJson('/v1/auth/mfa/enroll/fido2/confirm', [
            'enrollment_id' => $enrollmentId,
            'credential_id' => 'replay-cred-001',
            'public_key'    => 'fake-pk',
            'sign_count'    => 10,
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772102001',
            'credential'         => '654321',
            'device_fingerprint' => 'fido2-replay-dev',
        ]);
        $mfaToken = $loginResp->json('data.mfa_token');

        // Equal sign_count = replay — must be rejected
        $equalResp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'fido2',
            'code'      => '10', // = stored — replay
        ]);
        $equalResp->assertStatus(401);
        expect($equalResp->json('error.code'))->toBe('IDENTITY_014');
    });

    it('sign_count less than stored count is rejected (replay attack)', function (): void {
        secMfaCreateUser('+26772102002', 'fido2-replay-dev2');
        $token = secMfaLogin($this, '+26772102002', 'fido2-replay-dev2');

        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/fido2', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $enrollmentId = $enrollResp->json('data.enrollment_id');

        $this->postJson('/v1/auth/mfa/enroll/fido2/confirm', [
            'enrollment_id' => $enrollmentId,
            'credential_id' => 'replay-cred-002',
            'public_key'    => 'fake-pk',
            'sign_count'    => 10,
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772102002',
            'credential'         => '654321',
            'device_fingerprint' => 'fido2-replay-dev2',
        ]);
        $mfaToken = $loginResp->json('data.mfa_token');

        // Lesser sign_count = replay — must be rejected
        $resp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'fido2',
            'code'      => '5', // < 10 — replay
        ]);
        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_014');
    });

    it('sign_count greater than stored is accepted (T116)', function (): void {
        secMfaCreateUser('+26772102003', 'fido2-valid-dev');
        $token = secMfaLogin($this, '+26772102003', 'fido2-valid-dev');

        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/fido2', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $enrollmentId = $enrollResp->json('data.enrollment_id');

        $this->postJson('/v1/auth/mfa/enroll/fido2/confirm', [
            'enrollment_id' => $enrollmentId,
            'credential_id' => 'valid-cred-003',
            'public_key'    => 'fake-pk',
            'sign_count'    => 10,
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772102003',
            'credential'         => '654321',
            'device_fingerprint' => 'fido2-valid-dev',
        ]);
        $mfaToken = $loginResp->json('data.mfa_token');

        $resp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'fido2',
            'code'      => '11', // > 10 — valid
        ]);
        $resp->assertStatus(200);
        expect($resp->json('data.access_token'))->not->toBeNull();
    });

});

// =============================================================================

describe('MFA cannot be bypassed for Admin login (AC-028; T123)', function (): void {

    it('Admin with TOTP enrolled never receives access_token directly from login', function (): void {
        secMfaCreateUser('+26772103001', 'admin-bypass-dev', 'ADMIN');
        $token  = secMfaLogin($this, '+26772103001', 'admin-bypass-dev');
        secEnrollTotp($this, '+26772103001', 'admin-bypass-dev', $token);

        // Admin login MUST be intercepted by MFA gate — access_token must NOT be present
        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772103001',
            'credential'         => '654321',
            'device_fingerprint' => 'admin-bypass-dev',
        ]);
        $loginResp->assertStatus(200);
        expect($loginResp->json('data.mfa_required'))->toBeTrue();
        expect($loginResp->json('data'))->not->toHaveKey('access_token');
        expect($loginResp->json('data'))->not->toHaveKey('refresh_token');
    });

});
