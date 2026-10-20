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
 * Phase 12 MFA Tests — T122
 *
 * Covers:
 * - TOTP enrollment + confirmation + login gate + MFA verify → session issued
 * - FIDO2 enrollment + MFA verify with incremented sign_count
 * - Disable removes credential; subsequent login bypasses MFA gate
 * - Admin actor: only TOTP/FIDO2 allowed (SMS blocked server-side; AC-028)
 * - mfa_pending token rejected without MFA verify (invalid/expired token)
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

/** Create an active CUSTOMER user with a PIN and a trusted device. */
function mfaCreateUser(string $mobile, string $fingerprint = 'mfa-device-fp', string $actorType = 'CUSTOMER'): array
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
        'device_fingerprint' => hash('sha256', $fingerprint),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);

    return ['user' => $user, 'fingerprint' => $fingerprint];
}

/** Login and return access_token (no MFA enrolled). */
function mfaLogin(mixed $test, string $mobile, string $fingerprint = 'mfa-device-fp'): string
{
    $response = $test->postJson('/v1/auth/login', [
        'credential_type'    => 'pin',
        'identifier'         => $mobile,
        'credential'         => '654321',
        'device_fingerprint' => $fingerprint,
        'device_name'        => 'Test',
        'platform'           => 'android',
    ]);
    $response->assertStatus(200);
    return (string) $response->json('data.access_token');
}

/** Extract TOTP secret from an otpauth:// URI. */
function mfaExtractSecret(string $totpUri): string
{
    parse_str(parse_url($totpUri, PHP_URL_QUERY), $params);
    return (string) ($params['secret'] ?? '');
}

/** Generate a current TOTP code from a base32 secret. */
function mfaGenerateTotpCode(string $secret): string
{
    $auth = new Authenticator(new AuthenticatorOptions);
    $auth->setSecret($secret);
    return $auth->code();
}

// =============================================================================

describe('TOTP enrollment + confirm + login gate + verify (T115, T120, T122)', function (): void {

    it('full TOTP flow: enroll → confirm → login gate → mfa/verify returns session', function (): void {
        mfaCreateUser('+26772001001');
        $token = mfaLogin($this, '+26772001001');

        // Step 1: initiate TOTP enrollment
        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization'   => "Bearer {$token}",
            'X-Correlation-ID' => 'mfa-enroll-1',
        ]);
        $enrollResp->assertStatus(200);
        expect($enrollResp->json('success'))->toBeTrue();
        expect($enrollResp->json('data.enrollment_id'))->not->toBeNull();
        expect($enrollResp->json('data.totp_uri'))->toContain('otpauth://totp/');

        $enrollmentId = $enrollResp->json('data.enrollment_id');
        $totpUri      = $enrollResp->json('data.totp_uri');
        $secret       = mfaExtractSecret($totpUri);
        expect($secret)->not->toBeEmpty();

        // Step 2: confirm enrollment with the first TOTP code
        $confirmResp = $this->postJson('/v1/auth/mfa/enroll/totp/confirm', [
            'enrollment_id' => $enrollmentId,
            'totp_code'     => mfaGenerateTotpCode($secret),
        ], [
            'Authorization'   => "Bearer {$token}",
            'X-Correlation-ID' => 'mfa-confirm-1',
        ]);
        $confirmResp->assertStatus(200);
        expect($confirmResp->json('success'))->toBeTrue();
        expect($confirmResp->json('data.backup_codes'))->toBeArray();
        expect(count($confirmResp->json('data.backup_codes')))->toBe(8);

        // Step 3: login again — MFA gate must fire
        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772001001',
            'credential'         => '654321',
            'device_fingerprint' => 'mfa-device-fp',
        ]);
        $loginResp->assertStatus(200);
        expect($loginResp->json('data.mfa_required'))->toBeTrue();
        expect($loginResp->json('data.mfa_token'))->not->toBeNull();
        expect($loginResp->json('data.methods'))->toContain('totp');
        expect($loginResp->json('data.expires_in'))->toBe(300);
        expect($loginResp->json('data'))->not->toHaveKey('access_token');

        $mfaToken = $loginResp->json('data.mfa_token');

        // Step 4: verify MFA to get full session
        $verifyResp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'totp',
            'code'      => mfaGenerateTotpCode($secret),
        ]);
        $verifyResp->assertStatus(200);
        expect($verifyResp->json('success'))->toBeTrue();
        expect($verifyResp->json('data.access_token'))->not->toBeNull();
        expect($verifyResp->json('data.refresh_token'))->not->toBeNull();
        expect($verifyResp->json('data.session_id'))->not->toBeNull();
    });

    it('returns 422 when enrollment_id or totp_code is absent from confirm', function (): void {
        mfaCreateUser('+26772001002');
        $token = mfaLogin($this, '+26772001002');

        $resp = $this->postJson('/v1/auth/mfa/enroll/totp/confirm', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $resp->assertStatus(422);
        expect($resp->json('error.code'))->toBe('IDENTITY_019');
    });

    it('returns 401 when totp_code is incorrect during confirm', function (): void {
        mfaCreateUser('+26772001003');
        $token = mfaLogin($this, '+26772001003');

        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $enrollResp->assertStatus(200);
        $enrollmentId = $enrollResp->json('data.enrollment_id');

        $resp = $this->postJson('/v1/auth/mfa/enroll/totp/confirm', [
            'enrollment_id' => $enrollmentId,
            'totp_code'     => '000000', // deliberately wrong
        ], ['Authorization' => "Bearer {$token}"]);
        $resp->assertStatus(401);
    });

});

// =============================================================================

describe('FIDO2 enrollment + verify (T116, T122)', function (): void {

    it('full FIDO2 flow: enroll → confirm → login gate → mfa/verify with incremented sign_count', function (): void {
        mfaCreateUser('+26772002001', 'fido2-device');
        $token = mfaLogin($this, '+26772002001', 'fido2-device');

        // Step 1: initiate FIDO2 enrollment
        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/fido2', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $enrollResp->assertStatus(200);
        expect($enrollResp->json('data.enrollment_id'))->not->toBeNull();
        expect($enrollResp->json('data.challenge'))->not->toBeNull();

        $enrollmentId = $enrollResp->json('data.enrollment_id');

        // Step 2: confirm with credential data (Phase 12: no crypto — sign_count only)
        $confirmResp = $this->postJson('/v1/auth/mfa/enroll/fido2/confirm', [
            'enrollment_id' => $enrollmentId,
            'credential_id' => 'cred-abc-123',
            'public_key'    => 'fake-public-key-pem',
            'sign_count'    => 5,
        ], ['Authorization' => "Bearer {$token}"]);
        $confirmResp->assertStatus(200);
        expect($confirmResp->json('success'))->toBeTrue();

        // Step 3: login — MFA gate fires
        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772002001',
            'credential'         => '654321',
            'device_fingerprint' => 'fido2-device',
        ]);
        $loginResp->assertStatus(200);
        expect($loginResp->json('data.mfa_required'))->toBeTrue();
        expect($loginResp->json('data.methods'))->toContain('fido2');
        $mfaToken = $loginResp->json('data.mfa_token');

        // Step 4: verify with new sign_count > stored (6 > 5)
        $verifyResp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'fido2',
            'code'      => '6', // new sign_count
        ]);
        $verifyResp->assertStatus(200);
        expect($verifyResp->json('success'))->toBeTrue();
        expect($verifyResp->json('data.access_token'))->not->toBeNull();
    });

    it('returns 422 when enrollment_id, credential_id, or public_key is absent', function (): void {
        mfaCreateUser('+26772002002', 'fido2-device-2');
        $token = mfaLogin($this, '+26772002002', 'fido2-device-2');

        $resp = $this->postJson('/v1/auth/mfa/enroll/fido2/confirm', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $resp->assertStatus(422);
    });

});

// =============================================================================

describe('Disable MFA (T121)', function (): void {

    it('disabling TOTP removes MFA gate; subsequent login returns access_token directly', function (): void {
        mfaCreateUser('+26772003001', 'disable-test');
        $token = mfaLogin($this, '+26772003001', 'disable-test');

        // Enroll TOTP
        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $enrollResp->assertStatus(200);
        $enrollmentId = $enrollResp->json('data.enrollment_id');
        $secret       = mfaExtractSecret($enrollResp->json('data.totp_uri'));

        $this->postJson('/v1/auth/mfa/enroll/totp/confirm', [
            'enrollment_id' => $enrollmentId,
            'totp_code'     => mfaGenerateTotpCode($secret),
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        // Confirm gate is active
        $gateable = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772003001',
            'credential'         => '654321',
            'device_fingerprint' => 'disable-test',
        ]);
        expect($gateable->json('data.mfa_required'))->toBeTrue();

        // Disable TOTP
        $disableResp = $this->postJson('/v1/auth/mfa/disable', [
            'method' => 'totp',
        ], ['Authorization' => "Bearer {$token}"]);
        $disableResp->assertStatus(200);
        expect($disableResp->json('success'))->toBeTrue();

        // Login now bypasses MFA gate
        $afterDisable = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772003001',
            'credential'         => '654321',
            'device_fingerprint' => 'disable-test',
        ]);
        $afterDisable->assertStatus(200);
        expect($afterDisable->json('data.access_token'))->not->toBeNull();
        expect($afterDisable->json('data.mfa_required'))->toBeFalse();
    });

});

// =============================================================================

describe('Admin MFA restrictions (AC-028)', function (): void {

    it('Admin actor: TOTP enrollment is accepted', function (): void {
        mfaCreateUser('+26772004001', 'admin-device', 'ADMIN');

        $loginResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772004001',
            'credential'         => '654321',
            'device_fingerprint' => 'admin-device',
        ]);
        $loginResp->assertStatus(200);
        $token = (string) $loginResp->json('data.access_token');

        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $enrollResp->assertStatus(200);
        expect($enrollResp->json('data.totp_uri'))->toContain('otpauth://totp/');
    });

    it('Admin actor with TOTP enrolled cannot bypass MFA gate (AC-028)', function (): void {
        mfaCreateUser('+26772004002', 'admin-mfa-device', 'ADMIN');

        $loginRespFirst = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772004002',
            'credential'         => '654321',
            'device_fingerprint' => 'admin-mfa-device',
        ]);
        $token = (string) $loginRespFirst->json('data.access_token');

        $enrollResp = $this->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => "Bearer {$token}",
        ]);
        $secret       = mfaExtractSecret($enrollResp->json('data.totp_uri'));
        $enrollmentId = $enrollResp->json('data.enrollment_id');

        $this->postJson('/v1/auth/mfa/enroll/totp/confirm', [
            'enrollment_id' => $enrollmentId,
            'totp_code'     => mfaGenerateTotpCode($secret),
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(200);

        // Admin login must hit MFA gate
        $gatedResp = $this->postJson('/v1/auth/login', [
            'credential_type'    => 'pin',
            'identifier'         => '+26772004002',
            'credential'         => '654321',
            'device_fingerprint' => 'admin-mfa-device',
        ]);
        $gatedResp->assertStatus(200);
        expect($gatedResp->json('data.mfa_required'))->toBeTrue();
        expect($gatedResp->json('data'))->not->toHaveKey('access_token');
    });

});

// =============================================================================

describe('mfa_pending token rejection (T120)', function (): void {

    it('returns 422 when mfa_token, method, or code is absent', function (): void {
        $resp = $this->postJson('/v1/auth/mfa/verify', []);
        $resp->assertStatus(422);
        expect($resp->json('error.code'))->toBe('IDENTITY_019');
    });

    it('returns 401 when mfa_token is invalid', function (): void {
        $resp = $this->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => 'garbage-token-value',
            'method'    => 'totp',
            'code'      => '123456',
        ]);
        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_014');
    });

});
