<?php

declare(strict_types=1);

/**
 * E01 Central Identity â€” Runtime Contract Gate
 *
 * Validates implemented phases (1-13) against:
 *   - contracts/authentication-api.md
 *   - contracts/jwks-api.md
 *   - data-model.md
 *   - 25 seeded configuration keys (migration 2026_08_21_006000)
 *   - constitution.md principles
 *
 * Deferred / TBD items explicitly skipped:
 *   - TBD-004 Biometric SDK
 *   - Phase 14 Service Identity (T134-T142)
 *   - T215 PIN concurrency
 */

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use chillerlan\Authenticator\Authenticator;
use chillerlan\Authenticator\AuthenticatorOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;

$capturedGateOtp = null;

beforeEach(function () use (&$capturedGateOtp): void {
    $capturedGateOtp = null;
    $this->app->bind(OtpDeliveryContract::class, function () use (&$capturedGateOtp) {
        return new class ($capturedGateOtp) implements OtpDeliveryContract {
            public function __construct(private mixed &$otp) {}
            public function deliver(string $destination, string $otp, string $channelType, string $correlationId): OtpDeliveryResult
            {
                $this->otp = $otp;
                return new OtpDeliveryResult(true, 'gate-' . uniqid(), null);
            }
        };
    });
    Redis::connection('identity')->flushdb();
});

// ============================================================================
// HELPERS
// ============================================================================

function gateUser(string $mobile, string $pin = '246810', string $device = 'gate-dev', string $actorType = 'CUSTOMER'): array
{
    $user = app(UserRepositoryInterface::class)->create([
        'mobile_number' => $mobile,
        'status'        => 'active',
        'actor_type'    => $actorType,
    ]);

    if ($actorType === 'ADMIN') {
        $adminRole = DB::connection('identity')->table('identity.roles')
            ->where('slug', 'admin')->first(['id']);
        if ($adminRole) {
            DB::connection('identity')->table('identity.users')
                ->where('id', $user->id)
                ->update(['default_role_id' => $adminRole->id]);
        }
    }

    app(CredentialRepositoryInterface::class)->create([
        'user_id'              => $user->id,
        'credential_type'      => 'pin',
        'credential_hash'      => Hash::make($pin),
        'status'               => 'active',
        'failed_attempt_count' => 0,
    ]);
    app(DeviceRepositoryInterface::class)->create([
        'user_id'            => $user->id,
        'device_fingerprint' => hash('sha256', $device),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);
    return ['user' => $user, 'pin' => $pin, 'device' => $device];
}

function gateLogin(string $mobile, string $pin, string $device): array
{
    $resp = test()->postJson('/v1/auth/login', [
        'identifier'         => $mobile,
        'credential_type'    => 'pin',
        'credential'         => $pin,
        'device_fingerprint' => $device,
    ]);
    $resp->assertStatus(200);
    return [
        'access_token'  => (string) $resp->json('data.access_token'),
        'refresh_token' => (string) $resp->json('data.refresh_token'),
        'session_id'    => (string) $resp->json('data.session_id'),
    ];
}

// ============================================================================
// 1 + 2: Registration / authentication flows + response envelope
// ============================================================================

describe('[GATE-01] Registration flow and response envelope', function () use (&$capturedGateOtp): void {

    it('POST /v1/auth/register returns 202 with challenge_id', function () use (&$capturedGateOtp): void {
        $resp = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900001', 'actor_type' => 'CUSTOMER']);
        $resp->assertStatus(202);
        expect($resp->json('success'))->toBeTrue();
        expect($resp->json('data.challenge_id'))->not->toBeNull();
    });

    it('success envelope has success=true and data key', function () use (&$capturedGateOtp): void {
        $resp = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900002', 'actor_type' => 'CUSTOMER']);
        expect($resp->json('success'))->toBeTrue();
        expect($resp->json('data'))->not->toBeNull();
    });

    it('domain error envelope has success=false, error.code, error.message, error.trace_id', function (): void {
        // Use a domain error path (not a 422 validation error) â€” validation errors use Laravel's default format
        $resp = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26770000099',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'env-test',
        ]);
        expect($resp->json('success'))->toBeFalse();
        expect($resp->json('error.code'))->not->toBeNull();
        expect($resp->json('error.message'))->not->toBeNull();
        expect($resp->json('error.trace_id'))->not->toBeNull();
    });

    it('duplicate registration returns 409 IDENTITY_007 without identifier in body', function () use (&$capturedGateOtp): void {
        $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900003', 'actor_type' => 'CUSTOMER'])->assertStatus(202);
        $resp = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900003', 'actor_type' => 'CUSTOMER']);
        $resp->assertStatus(409);
        expect($resp->json('error.code'))->toBe('IDENTITY_007');
        expect($resp->getContent())->not->toContain('+26771900003');
    });

    it('X-Correlation-ID echoed on controller-handled routes (register, login, otp)', function () use (&$capturedGateOtp): void {
        // Controllers explicitly echo the correlation ID header on all responses they handle.
        // NOTE: JWKS endpoint does NOT echo it â€” see GATE contract deviation CDE-001.
        $corrId = '550e8400-e29b-41d4-a716-446655440000';
        $resp   = $this->postJson('/v1/auth/register', [
            'mobile_number' => '+26771900004',
            'actor_type'    => 'CUSTOMER',
        ], ['X-Correlation-ID' => $corrId]);
        expect($resp->headers->get('X-Correlation-ID'))->toBe($corrId);
    });

});

// ============================================================================
// 3: OTP generation / verification / expiry / replay
// ============================================================================

describe('[GATE-03] OTP generation, expiry, replay prevention', function () use (&$capturedGateOtp): void {

    it('OTP verify 200 returns data.setup_token and data.next_step=SET_PIN for registration', function () use (&$capturedGateOtp): void {
        $reg         = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900010', 'actor_type' => 'CUSTOMER']);
        $challengeId = $reg->json('data.challenge_id');
        $verify      = $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $challengeId, 'otp' => $capturedGateOtp]);

        $verify->assertStatus(200);
        expect($verify->json('data.setup_token'))->not->toBeNull();
        expect($verify->json('data.next_step'))->toBe('SET_PIN');
    });

    it('correct OTP consumed â€” replay returns 401 IDENTITY_003', function () use (&$capturedGateOtp): void {
        $reg         = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900011', 'actor_type' => 'CUSTOMER']);
        $challengeId = $reg->json('data.challenge_id');
        $otp         = $capturedGateOtp;
        $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $challengeId, 'otp' => $otp])->assertStatus(200);

        $replay = $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $challengeId, 'otp' => $otp]);
        $replay->assertStatus(401);
        expect($replay->json('error.code'))->toBe('IDENTITY_003');
    });

    it('wrong OTP returns 401 IDENTITY_003', function () use (&$capturedGateOtp): void {
        $reg         = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900012', 'actor_type' => 'CUSTOMER']);
        $challengeId = $reg->json('data.challenge_id');
        $resp        = $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $challengeId, 'otp' => '000000']);
        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_003');
    });

    it('OTP stored as SHA-256 hash in DB â€” not plaintext', function () use (&$capturedGateOtp): void {
        $reg         = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900013', 'actor_type' => 'CUSTOMER']);
        $challengeId = $reg->json('data.challenge_id');
        $otp         = $capturedGateOtp;

        $row = DB::connection('identity')->table('identity.otp_challenges')->where('id', $challengeId)->first();
        expect($row->otp_hash)->toBe(hash('sha256', $otp));
        expect($row->otp_hash)->not->toBe($otp);
    });

});

// ============================================================================
// 4: Login failure / lockout
// ============================================================================

describe('[GATE-04] Login failure and lockout behavior', function (): void {

    it('wrong PIN returns 401 IDENTITY_002 â€” no identifier in body', function (): void {
        gateUser('+26771900020', '246810', 'gate-020');
        $resp = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771900020',
            'credential_type'    => 'pin',
            'credential'         => '999999',
            'device_fingerprint' => 'gate-020',
        ]);
        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_002');
        expect($resp->getContent())->not->toContain('+26771900020');
    });

    it('unknown identifier returns 401 IDENTITY_002 â€” no 404 disclosure', function (): void {
        $resp = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26770000001',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'gate-unk',
        ]);
        expect($resp->status())->toBe(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_002');
    });

    it('account locked after max failed attempts â†’ 423 IDENTITY_004', function (): void {
        gateUser('+26771900021', '246810', 'gate-021');
        $max = app(ConfigurationSecurityService::class)->getLockoutMaxFailedAttempts();

        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/v1/auth/login', [
                'identifier'         => '+26771900021',
                'credential_type'    => 'pin',
                'credential'         => '999999',
                'device_fingerprint' => 'gate-021',
            ]);
        }

        $resp = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771900021',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'gate-021',
        ]);
        $resp->assertStatus(423);
        expect($resp->json('error.code'))->toBe('IDENTITY_004');
    });

});

// ============================================================================
// 5: Access token issuance
// ============================================================================

describe('[GATE-05] Access token issuance', function (): void {

    it('login returns access_token, refresh_token, token_type=Bearer, expires_in, session_id', function (): void {
        gateUser('+26771900030', '246810', 'gate-030');
        $resp = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771900030',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'gate-030',
        ]);
        $resp->assertStatus(200);
        expect($resp->json('data.access_token'))->not->toBeNull();
        expect($resp->json('data.refresh_token'))->not->toBeNull();
        expect($resp->json('data.token_type'))->toBe('Bearer');
        expect($resp->json('data.expires_in'))->toBeGreaterThan(0);
        expect($resp->json('data.session_id'))->not->toBeNull();
        expect($resp->json('data.mfa_required'))->toBeFalse();
        expect($resp->json('data.new_device_detected'))->toBeFalse();
    });

    it('access_token is a valid 3-segment JWT', function (): void {
        gateUser('+26771900031', '246810', 'gate-031');
        $resp = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771900031',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'gate-031',
        ]);
        expect(count(explode('.', $resp->json('data.access_token'))))->toBe(3);
    });

    it('JWT header has alg=ES256 and kid (12 hex chars)', function (): void {
        gateUser('+26771900032', '246810', 'gate-032');
        $resp   = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771900032',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'gate-032',
        ]);
        $header = json_decode(base64_decode(explode('.', $resp->json('data.access_token'))[0]), true);
        expect($header['alg'])->toBe('ES256');
        expect($header['kid'])->not->toBeNull();
        expect(strlen($header['kid']))->toBe(12);
        expect(ctype_xdigit($header['kid']))->toBeTrue();
    });

    it('JWT payload has sub, iss, exp, iat, session_id, actor_type; no raw credential', function (): void {
        gateUser('+26771900033', '246810', 'gate-033');
        $resp   = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771900033',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'gate-033',
        ]);
        $rawJwt = $resp->json('data.access_token');
        $parts  = explode('.', $rawJwt);
        $pad    = strlen($parts[1]) % 4;
        $payload = json_decode(base64_decode($pad ? $parts[1] . str_repeat('=', 4 - $pad) : $parts[1]), true);

        expect($payload['sub'])->not->toBeNull();
        expect($payload['iss'])->not->toBeNull();
        expect($payload['exp'])->toBeGreaterThan(time());
        expect($payload['iat'])->not->toBeNull();
        expect($payload['session_id'])->not->toBeNull();
        expect($payload['actor_type'])->not->toBeNull();
        expect($rawJwt)->not->toContain('246810');
    });

});

// ============================================================================
// 6: Refresh token rotation / reuse detection
// ============================================================================

describe('[GATE-06] Refresh token rotation and reuse detection', function (): void {

    it('refresh returns new access_token + new refresh_token', function (): void {
        gateUser('+26771900040', '246810', 'gate-040');
        $session = gateLogin('+26771900040', '246810', 'gate-040');

        $resp = $this->postJson('/v1/auth/token/refresh', ['refresh_token' => $session['refresh_token']]);
        $resp->assertStatus(200);
        expect($resp->json('data.access_token'))->not->toBeNull();
        expect($resp->json('data.refresh_token'))->not->toBeNull();
        expect($resp->json('data.refresh_token'))->not->toBe($session['refresh_token']);
    });

    it('reused refresh token after rotation â†’ 401 IDENTITY_015', function (): void {
        gateUser('+26771900041', '246810', 'gate-041');
        $session = gateLogin('+26771900041', '246810', 'gate-041');

        $this->postJson('/v1/auth/token/refresh', ['refresh_token' => $session['refresh_token']])->assertStatus(200);

        $replay = $this->postJson('/v1/auth/token/refresh', ['refresh_token' => $session['refresh_token']]);
        $replay->assertStatus(401);
        expect($replay->json('error.code'))->toBe('IDENTITY_015');
    });

    it('refresh token is opaque â€” not a 3-segment JWT', function (): void {
        gateUser('+26771900042', '246810', 'gate-042');
        $session = gateLogin('+26771900042', '246810', 'gate-042');
        expect(count(explode('.', $session['refresh_token'])))->not->toBe(3);
    });

});

// ============================================================================
// 7 + 8: JWKS publication and ES256 signature verification
// ============================================================================

describe('[GATE-07+08] JWKS publication and ES256', function (): void {

    it('GET /v1/.well-known/jwks.json returns 200 with non-empty keys array', function (): void {
        $resp = $this->getJson('/v1/.well-known/jwks.json');
        $resp->assertStatus(200);
        expect($resp->json('keys'))->not->toBeNull();
        expect(count($resp->json('keys')))->toBeGreaterThan(0);
    });

    it('JWKS key has kty=EC, crv=P-256, alg=ES256, use=sig, kid (12 hex chars)', function (): void {
        $resp = $this->getJson('/v1/.well-known/jwks.json');
        $key  = $resp->json('keys.0');
        expect($key['kty'])->toBe('EC');
        expect($key['crv'])->toBe('P-256');
        expect($key['alg'])->toBe('ES256');
        expect($key['use'])->toBe('sig');
        expect(strlen($key['kid']))->toBe(12);
        expect(ctype_xdigit($key['kid']))->toBeTrue();
    });

    it('private key d parameter absent from JWKS response', function (): void {
        $resp = $this->getJson('/v1/.well-known/jwks.json');
        expect($resp->getContent())->not->toContain('"d"');
        expect($resp->json('keys.0.d'))->toBeNull();
    });

    it('JWKS kid matches JWT header kid', function (): void {
        gateUser('+26771900050', '246810', 'gate-050');
        $loginResp = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771900050',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'gate-050',
        ]);
        $header   = json_decode(base64_decode(explode('.', $loginResp->json('data.access_token'))[0]), true);
        $jwksKids = array_column($this->getJson('/v1/.well-known/jwks.json')->json('keys'), 'kid');
        expect($jwksKids)->toContain($header['kid']);
    });

    it('JWKS response includes Cache-Control max-age header', function (): void {
        $resp = $this->getJson('/v1/.well-known/jwks.json');
        expect($resp->headers->get('Cache-Control'))->toContain('max-age=');
    });

});

// ============================================================================
// 9: JWT kid / key rotation
// ============================================================================

describe('[GATE-09] JWT kid and key rotation', function (): void {

    it('token with unknown kid rejected by auth middleware with 401', function (): void {
        $fakeJwt = implode('.', [
            base64_encode(json_encode(['alg' => 'ES256', 'kid' => 'deadbeef0000', 'typ' => 'JWT'])),
            base64_encode(json_encode(['sub' => 'fake', 'exp' => time() + 900])),
            'fakesignature',
        ]);
        $resp = $this->getJson('/v1/auth/sessions', ['Authorization' => 'Bearer ' . $fakeJwt]);
        $resp->assertStatus(401);
    });

    it('malformed / expired token rejected with 401', function (): void {
        $resp = $this->getJson('/v1/auth/sessions', ['Authorization' => 'Bearer eyJhbGciOiJFUzI1NiJ9.eyJleHAiOjF9.sig']);
        $resp->assertStatus(401);
    });

});

// ============================================================================
// 10 + 11 + 12: Service identity / actor attribution / delegation â€” DEFERRED
// ============================================================================

describe('[GATE-10-12] Service identity, actor attribution, delegation â€” DEFERRED Phase 14', function (): void {
    it('Phase 14 service identity not yet implemented')->skip('Phase 14 (T134-T142) not yet implemented');
    it('Phase 14 delegation not yet implemented')->skip('Phase 14 (T134-T142) not yet implemented');
});

// ============================================================================
// 13: Session / device security
// ============================================================================

describe('[GATE-13] Session and device security', function (): void {

    it('revoked session rejected on next request â†’ 401', function (): void {
        gateUser('+26771900070', '246810', 'gate-070');
        $session = gateLogin('+26771900070', '246810', 'gate-070');

        $this->postJson('/v1/auth/logout', [], ['Authorization' => 'Bearer ' . $session['access_token']])->assertStatus(200);

        $resp = $this->getJson('/v1/auth/sessions', ['Authorization' => 'Bearer ' . $session['access_token']]);
        $resp->assertStatus(401);
    });

    it('untrusted device fingerprint â†’ 403 IDENTITY_023 with challenge_id', function (): void {
        gateUser('+26771900071', '246810', 'gate-071');
        $resp = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771900071',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'brand-new-device-xyz',
        ]);
        expect($resp->status())->toBe(403);
        expect($resp->json('error.code'))->toBe('IDENTITY_023');
        expect($resp->json('error.challenge_id'))->not->toBeNull();
    });

    it('device fingerprint stored as SHA-256 hash (64 hex chars) â€” not raw', function (): void {
        gateUser('+26771900072', '246810', 'gate-072');
        $device = DB::connection('identity')
            ->table('identity.devices')
            ->where('device_fingerprint', hash('sha256', 'gate-072'))
            ->first();
        expect($device)->not->toBeNull();
        expect($device->device_fingerprint)->not->toBe('gate-072');
        expect(strlen($device->device_fingerprint))->toBe(64);
        expect(ctype_xdigit($device->device_fingerprint))->toBeTrue();
    });

    it('ip_hash in sessions is HMAC-derived â€” not a raw IP address', function (): void {
        gateUser('+26771900073', '246810', 'gate-073');
        gateLogin('+26771900073', '246810', 'gate-073');

        $session = DB::connection('identity')->table('identity.sessions')->orderByDesc('created_at')->first();
        expect($session)->not->toBeNull();
        expect($session->ip_hash)->not->toContain('127.0.0.1');
        expect($session->ip_hash)->not->toContain('::1');
        expect(ctype_xdigit($session->ip_hash))->toBeTrue();
    });

});

// ============================================================================
// 14: MFA gate (Phase 12 T115-T123)
// ============================================================================

describe('[GATE-14] MFA gate â€” Phase 12 (T115-T123)', function (): void {

    it('login with MFA enrolled returns mfa_required=true and mfa_token instead of session', function (): void {
        $data = gateUser('+26771900200', '246810', 'gate-200');
        $session = gateLogin('+26771900200', '246810', 'gate-200');

        // Enroll TOTP
        $enrollResp = test()->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => 'Bearer ' . $session['access_token'],
        ]);
        $enrollResp->assertStatus(200);
        parse_str(parse_url($enrollResp->json('data.totp_uri'), PHP_URL_QUERY), $params);
        $secret       = (string) ($params['secret'] ?? '');
        $enrollmentId = $enrollResp->json('data.enrollment_id');

        test()->postJson('/v1/auth/mfa/enroll/totp/confirm', [
            'enrollment_id' => $enrollmentId,
            'totp_code'     => (function () use ($secret) {
                $auth = new Authenticator(new AuthenticatorOptions);
                $auth->setSecret($secret);
                return $auth->code();
            })(),
        ], ['Authorization' => 'Bearer ' . $session['access_token']])->assertStatus(200);

        // Login again — gate must fire
        $loginResp = test()->postJson('/v1/auth/login', [
            'identifier'         => '+26771900200',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'gate-200',
        ]);
        $loginResp->assertStatus(200);
        expect($loginResp->json('data.mfa_required'))->toBeTrue();
        expect($loginResp->json('data.mfa_token'))->not->toBeNull();
        expect($loginResp->json('data.methods'))->toContain('totp');
        expect($loginResp->json('data'))->not->toHaveKey('access_token');
    });

    it('mfa/verify with valid TOTP code returns full session tokens', function (): void {
        gateUser('+26771900201', '246810', 'gate-201');
        $session = gateLogin('+26771900201', '246810', 'gate-201');

        $enrollResp = test()->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => 'Bearer ' . $session['access_token'],
        ]);
        parse_str(parse_url($enrollResp->json('data.totp_uri'), PHP_URL_QUERY), $params);
        $secret       = (string) ($params['secret'] ?? '');
        $enrollmentId = $enrollResp->json('data.enrollment_id');
        $auth         = new Authenticator(new AuthenticatorOptions);
        $auth->setSecret($secret);

        test()->postJson('/v1/auth/mfa/enroll/totp/confirm', [
            'enrollment_id' => $enrollmentId,
            'totp_code'     => $auth->code(),
        ], ['Authorization' => 'Bearer ' . $session['access_token']])->assertStatus(200);

        $loginResp = test()->postJson('/v1/auth/login', [
            'identifier'         => '+26771900201',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'gate-201',
        ]);
        $mfaToken = $loginResp->json('data.mfa_token');

        $verifyResp = test()->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'totp',
            'code'      => $auth->code(),
        ]);
        $verifyResp->assertStatus(200);
        expect($verifyResp->json('data.access_token'))->not->toBeNull();
        expect($verifyResp->json('data.refresh_token'))->not->toBeNull();
        expect($verifyResp->json('data.session_id'))->not->toBeNull();
    });

    it('FIDO2 sign_count replay rejected (FR-E01-049; T116)', function (): void {
        gateUser('+26771900202', '246810', 'gate-202');
        $session = gateLogin('+26771900202', '246810', 'gate-202');

        $f2Enroll = test()->postJson('/v1/auth/mfa/enroll/fido2', [], [
            'Authorization' => 'Bearer ' . $session['access_token'],
        ]);
        test()->postJson('/v1/auth/mfa/enroll/fido2/confirm', [
            'enrollment_id' => $f2Enroll->json('data.enrollment_id'),
            'credential_id' => 'gate-cred-001',
            'public_key'    => 'gate-pk',
            'sign_count'    => 10,
        ], ['Authorization' => 'Bearer ' . $session['access_token']])->assertStatus(200);

        $loginResp = test()->postJson('/v1/auth/login', [
            'identifier'         => '+26771900202',
            'credential_type'    => 'pin',
            'credential'         => '246810',
            'device_fingerprint' => 'gate-202',
        ]);
        $mfaToken = $loginResp->json('data.mfa_token');

        // Equal sign_count = replay → rejected
        $replayResp = test()->postJson('/v1/auth/mfa/verify', [
            'mfa_token' => $mfaToken,
            'method'    => 'fido2',
            'code'      => '10',
        ]);
        $replayResp->assertStatus(401);
        expect($replayResp->json('error.code'))->toBe('IDENTITY_014');
    });

    it('audit event identity.mfa.enabled emitted on TOTP enrollment (T121)', function (): void {
        gateUser('+26771900203', '246810', 'gate-203');
        $session = gateLogin('+26771900203', '246810', 'gate-203');

        $enrollResp = test()->postJson('/v1/auth/mfa/enroll/totp', [], [
            'Authorization' => 'Bearer ' . $session['access_token'],
        ]);
        parse_str(parse_url($enrollResp->json('data.totp_uri'), PHP_URL_QUERY), $params);
        $secret = (string) ($params['secret'] ?? '');
        $auth   = new Authenticator(new AuthenticatorOptions);
        $auth->setSecret($secret);

        test()->postJson('/v1/auth/mfa/enroll/totp/confirm', [
            'enrollment_id' => $enrollResp->json('data.enrollment_id'),
            'totp_code'     => $auth->code(),
        ], ['Authorization' => 'Bearer ' . $session['access_token']])->assertStatus(200);

        $audit = DB::connection('platform')->table('platform.audit_events')
            ->where('action', 'identity.mfa.enabled')
            ->orderByDesc('occurred_at')
            ->first();
        expect($audit)->not->toBeNull();
        expect($audit->outcome)->toBe('success');
    });

});

// ============================================================================
// 15: Step-Up Authentication — Phase 13 (T124-T133)
// ============================================================================

describe('[GATE-15] Step-Up Authentication - Phase 13 (T124-T133)', function (): void {

    it('POST /v1/auth/step-up/initiate requires Bearer JWT (401 without)', function (): void {
        $resp = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
        ]);
        $resp->assertStatus(401);
    });

    it('POST /v1/auth/step-up/verify requires Bearer JWT (401 without)', function (): void {
        $resp = test()->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => 'some-token',
            'method'        => 'pin',
            'credential'    => '246810',
        ]);
        $resp->assertStatus(401);
    });

    it('initiate returns step_up_required=true for amount above threshold (10000 BWP)', function (): void {
        gateUser('+26771915001', '246810', 'gate-15-a');
        $session = gateLogin('+26771915001', '246810', 'gate-15-a');

        $resp = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
        ], ['Authorization' => 'Bearer ' . $session['access_token']]);

        $resp->assertStatus(200);
        expect($resp->json('success'))->toBeTrue();
        expect($resp->json('data.step_up_required'))->toBeTrue();
        expect($resp->json('data.step_up_token'))->not->toBeNull();
        expect($resp->json('data.methods'))->toBeArray();
        expect($resp->json('data.expires_at'))->not->toBeNull();
    });

    it('initiate returns step_up_required=false for amount below threshold', function (): void {
        gateUser('+26771915002', '246810', 'gate-15-b');
        $session = gateLogin('+26771915002', '246810', 'gate-15-b');

        $resp = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:small',
            'operation_context' => ['amount' => 500, 'currency' => 'BWP'],
        ], ['Authorization' => 'Bearer ' . $session['access_token']]);

        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeFalse();
    });

    it('risk signal triggers step-up regardless of amount', function (): void {
        gateUser('+26771915003', '246810', 'gate-15-c');
        $session = gateLogin('+26771915003', '246810', 'gate-15-c');

        $resp = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'risk:flagged',
            'operation_context' => ['amount' => 100, 'currency' => 'BWP', 'risk_required' => true],
        ], ['Authorization' => 'Bearer ' . $session['access_token']]);

        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeTrue();
    });

    it('full step-up flow: initiate, verify with PIN, scoped step-up access token returned', function (): void {
        gateUser('+26771915004', '246810', 'gate-15-d');
        $session = gateLogin('+26771915004', '246810', 'gate-15-d');
        $bearer  = ['Authorization' => 'Bearer ' . $session['access_token']];

        $init = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 25000, 'currency' => 'BWP'],
        ], $bearer);
        $init->assertStatus(200);
        expect($init->json('data.step_up_required'))->toBeTrue();
        $challengeToken = $init->json('data.step_up_token');

        $verify = test()->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'pin',
            'credential'    => '246810',
        ], $bearer);

        $verify->assertStatus(200);
        expect($verify->json('success'))->toBeTrue();
        expect($verify->json('data.step_up_access_token'))->not->toBeNull();
        expect($verify->json('data.scope'))->toBe('payment:high-value');
        expect($verify->json('data.expires_in'))->toBeInt()->toBeGreaterThan(0);
    });

    it('step-up access token has aud = step-up:{scope} not the general app.url (AC-013)', function (): void {
        gateUser('+26771915005', '246810', 'gate-15-e');
        $session = gateLogin('+26771915005', '246810', 'gate-15-e');
        $bearer  = ['Authorization' => 'Bearer ' . $session['access_token']];

        $init = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:contract-gate',
            'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
        ], $bearer);
        $init->assertStatus(200);

        $verify = test()->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $init->json('data.step_up_token'),
            'method'        => 'pin',
            'credential'    => '246810',
        ], $bearer);
        $verify->assertStatus(200);

        $parts  = explode('.', $verify->json('data.step_up_access_token'));
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $aud    = is_array($claims['aud'] ?? null) ? implode(',', $claims['aud']) : (string) ($claims['aud'] ?? '');

        expect($aud)->toContain('step-up:payment:contract-gate');
        expect($claims['step_up_scope'] ?? null)->toBe('payment:contract-gate');
    });

    it('wrong credential returns 401 IDENTITY_014', function (): void {
        gateUser('+26771915006', '246810', 'gate-15-f');
        $session = gateLogin('+26771915006', '246810', 'gate-15-f');
        $bearer  = ['Authorization' => 'Bearer ' . $session['access_token']];

        $init = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
        ], $bearer);
        $init->assertStatus(200);

        $resp = test()->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $init->json('data.step_up_token'),
            'method'        => 'pin',
            'credential'    => '000000',
        ], $bearer);

        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_014');
    });

    it('step-up challenge is single-use: replay returns 401', function (): void {
        gateUser('+26771915007', '246810', 'gate-15-g');
        $session = gateLogin('+26771915007', '246810', 'gate-15-g');

        $init = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
        ], ['Authorization' => 'Bearer ' . $session['access_token']]);

        $challengeToken = $init->json('data.step_up_token');
        $payload        = ['step_up_token' => $challengeToken, 'method' => 'pin', 'credential' => '246810'];
        $headers        = ['Authorization' => 'Bearer ' . $session['access_token']];

        test()->postJson('/v1/auth/step-up/verify', $payload, $headers)->assertStatus(200);
        test()->postJson('/v1/auth/step-up/verify', $payload, $headers)->assertStatus(401);
    });

    it('missing required fields returns 422 IDENTITY_019', function (): void {
        gateUser('+26771915008', '246810', 'gate-15-h');
        $session = gateLogin('+26771915008', '246810', 'gate-15-h');

        $resp = test()->postJson('/v1/auth/step-up/verify', [], [
            'Authorization' => 'Bearer ' . $session['access_token'],
        ]);
        $resp->assertStatus(422);
        expect($resp->json('error.code'))->toBe('IDENTITY_019');
    });

    it('FX unavailable (non-BWP currency) triggers fail-safe step-up (BR-024)', function (): void {
        gateUser('+26771915009', '246810', 'gate-15-i');
        $session = gateLogin('+26771915009', '246810', 'gate-15-i');

        $resp = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:fx-failsafe',
            'operation_context' => ['amount' => 100, 'currency' => 'USD'],
        ], ['Authorization' => 'Bearer ' . $session['access_token']]);

        $resp->assertStatus(200);
        expect($resp->json('data.step_up_required'))->toBeTrue();
    });

    it('auth.step_up.token_ttl is a positive integer from ConfigurationSecurityService', function (): void {
        $svc = app(ConfigurationSecurityService::class);
        $ttl = $svc->getStepUpTokenTtl();
        expect($ttl)->toBeInt()->toBeGreaterThan(0);
    });

});

// ============================================================================
// 16: auth.step_up_threshold runtime-configurable seed
// ============================================================================

describe('[GATE-16] auth.step_up_threshold runtime-configurable', function (): void {

    it('ConfigurationSecurityService returns 10000 (int) from DB-backed config', function (): void {
        $svc = app(ConfigurationSecurityService::class);
        expect($svc->getStepUpThreshold())->toBe(10000);
        expect($svc->getStepUpThresholdCurrency())->toBe('BWP');
    });

    it('auth.step_up_threshold seeded in platform.configuration_values with value 10000', function (): void {
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.step_up_threshold')
            ->where('status', 'active')
            ->first();
        expect($row)->not->toBeNull();
        expect($row->config_value)->toBe('10000');
    });

    it('auth.step_up_threshold_currency seeded as BWP', function (): void {
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'auth.step_up_threshold_currency')
            ->where('status', 'active')
            ->first();
        expect($row)->not->toBeNull();
        expect($row->config_value)->toBe('BWP');
    });

});

// ============================================================================
// 17: PIN set / change / reset / unblock (Phase 11)
// ============================================================================

describe('[GATE-17] PIN set, change, reset, unblock', function () use (&$capturedGateOtp): void {

    it('PIN set via X-Setup-Token returns 201 and stores Argon2id hash', function () use (&$capturedGateOtp): void {
        $reg        = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900080', 'actor_type' => 'CUSTOMER']);
        $cid        = $reg->json('data.challenge_id');
        $verify     = $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $cid, 'otp' => $capturedGateOtp]);
        $setupToken = $verify->json('data.setup_token');

        $setResp = $this->postJson('/v1/auth/pin/set', ['pin' => '246810'], ['X-Setup-Token' => $setupToken]);
        $setResp->assertStatus(201);

        $cred = DB::connection('identity')
            ->table('identity.credentials')
            ->where('credential_type', 'pin')
            ->orderByDesc('created_at')
            ->first();
        expect(str_starts_with($cred->credential_hash, '$argon2id'))->toBeTrue();
    });

    it('PIN change with wrong current PIN â†’ 401 IDENTITY_002', function (): void {
        gateUser('+26771900081', '246810', 'gate-081');
        $session = gateLogin('+26771900081', '246810', 'gate-081');

        $resp = $this->postJson(
            '/v1/auth/pin/change',
            ['current_pin' => '999999', 'new_pin' => '357911'],
            ['Authorization' => 'Bearer ' . $session['access_token']],
        );
        $resp->assertStatus(401);
        expect($resp->json('error.code'))->toBe('IDENTITY_002');
    });

    it('PIN reset initiate returns 200 with challenge_id', function (): void {
        gateUser('+26771900082', '246810', 'gate-082');
        $resp = $this->postJson('/v1/auth/pin/reset/initiate', ['mobile_number' => '+26771900082']);
        $resp->assertStatus(200);
        expect($resp->json('data.challenge_id'))->not->toBeNull();
    });

    it('PIN reset initiate for unknown mobile returns 200 â€” no identifier disclosure', function (): void {
        $resp = $this->postJson('/v1/auth/pin/reset/initiate', ['mobile_number' => '+26770000099']);
        $resp->assertStatus(200);
    });

    it('PIN unblock via OTP successfully unblocks the credential', function () use (&$capturedGateOtp): void {
        $data    = gateUser('+26771900083', '246810', 'gate-083');
        $session = gateLogin('+26771900083', '246810', 'gate-083');
        $max     = app(ConfigurationSecurityService::class)->getLockoutMaxFailedAttempts();

        for ($i = 0; $i < $max; $i++) {
            $this->postJson(
                '/v1/auth/pin/change',
                ['current_pin' => '999999', 'new_pin' => '357911'],
                ['Authorization' => 'Bearer ' . $session['access_token']],
            );
        }

        $initResp    = $this->postJson('/v1/auth/pin/unblock', ['mobile_number' => '+26771900083']);
        $challengeId = $initResp->json('data.challenge_id');
        expect($challengeId)->not->toBeNull();

        $unblockResp = $this->postJson('/v1/auth/pin/unblock', [
            'challenge_id' => $challengeId,
            'otp'          => $capturedGateOtp,
        ]);
        $unblockResp->assertStatus(200);

        $cred = app(CredentialRepositoryInterface::class)->findByUserAndType($data['user']->id, 'pin');
        expect($cred->status)->toBe('active');
        expect($cred->failed_attempt_count)->toBe(0);
    });

});

// ============================================================================
// 18: PIN concurrency â€” T215 deferred
// ============================================================================

describe('[GATE-18] PIN concurrency â€” T215 deferred', function (): void {
    it('T215 PIN concurrency test not yet implemented')->skip('T215 explicitly listed as future task in tasks.md');
});

// ============================================================================
// 19 + 20: Audit events
// ============================================================================

describe('[GATE-19+20] Audit events', function () use (&$capturedGateOtp): void {

    it('OTP verify emits identity.user.registered audit event on successful registration', function () use (&$capturedGateOtp): void {
        $reg = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900090', 'actor_type' => 'CUSTOMER']);
        $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $reg->json('data.challenge_id'), 'otp' => $capturedGateOtp]);

        $audit = DB::connection('platform')->table('platform.audit_events')
            ->where('action', 'identity.user.registered')->orderByDesc('occurred_at')->first();
        expect($audit)->not->toBeNull();
        expect($audit->outcome)->toBe('success');
    });

    it('successful login emits identity.session.created audit event', function (): void {
        gateUser('+26771900091', '246810', 'gate-091');
        gateLogin('+26771900091', '246810', 'gate-091');

        $audit = DB::connection('platform')->table('platform.audit_events')
            ->where('action', 'identity.session.created')->orderByDesc('occurred_at')->first();
        expect($audit)->not->toBeNull();
        expect($audit->outcome)->toBe('success');
    });

    it('PIN set emits identity.credential.pin-created audit event with no raw PIN in payload', function () use (&$capturedGateOtp): void {
        $reg    = $this->postJson('/v1/auth/register', ['mobile_number' => '+26771900092', 'actor_type' => 'CUSTOMER']);
        $verify = $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $reg->json('data.challenge_id'), 'otp' => $capturedGateOtp]);
        $this->postJson('/v1/auth/pin/set', ['pin' => '246810'], ['X-Setup-Token' => $verify->json('data.setup_token')]);

        $audit = DB::connection('platform')->table('platform.audit_events')
            ->where('action', 'identity.credential.pin-created')->orderByDesc('occurred_at')->first();
        expect($audit)->not->toBeNull();
        expect(json_encode($audit))->not->toContain('246810');
    });

    it('audit events do not contain raw IP addresses', function (): void {
        gateUser('+26771900093', '246810', 'gate-093');
        gateLogin('+26771900093', '246810', 'gate-093');

        $audits = DB::connection('platform')->table('platform.audit_events')->orderByDesc('occurred_at')->limit(20)->get();
        foreach ($audits as $audit) {
            $raw = json_encode($audit);
            expect($raw)->not->toContain('"127.0.0.1"');
            expect($raw)->not->toContain('"::1"');
        }
    });

    it('failed login emits identity.session.login-failed audit event', function (): void {
        gateUser('+26771900094', '246810', 'gate-094');
        $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771900094',
            'credential_type'    => 'pin',
            'credential'         => '999999',
            'device_fingerprint' => 'gate-094',
        ]);
        $audit = DB::connection('platform')->table('platform.audit_events')
            ->where('action', 'identity.session.login-failed')->orderByDesc('occurred_at')->first();
        expect($audit)->not->toBeNull();
        expect($audit->outcome)->toBe('failure');
    });

});

// ============================================================================
// 23 + 24 + 25: PostgreSQL schema-per-module isolation
// ============================================================================

describe('[GATE-23-25] PostgreSQL schema-per-module isolation', function (): void {

    it('identity tables exist in identity schema', function (): void {
        $tables = ['identity.users', 'identity.credentials', 'identity.sessions', 'identity.devices', 'identity.otp_challenges'];
        foreach ($tables as $table) {
            $count = DB::connection('identity')->table($table)->count();
            expect($count)->toBeGreaterThanOrEqual(0);
        }
    });

    it('identity Eloquent models declare connection=identity', function (): void {
        $classes = [
            \App\Modules\Identity\Infrastructure\Models\User::class,
            \App\Modules\Identity\Infrastructure\Models\Credential::class,
            \App\Modules\Identity\Infrastructure\Models\Session::class,
            \App\Modules\Identity\Infrastructure\Models\Device::class,
            \App\Modules\Identity\Infrastructure\Models\OtpChallenge::class,
        ];
        foreach ($classes as $cls) {
            expect((new $cls())->getConnectionName())->toBe('identity');
        }
    });

    it('no cross-schema FK from identity schema to platform schema', function (): void {
        $fks = DB::connection('identity')->select("
            SELECT conname, confrelid::regclass::text AS referenced
            FROM pg_constraint
            WHERE contype = 'f'
              AND connamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'identity')
        ");
        foreach ($fks as $fk) {
            expect($fk->referenced)->not->toContain('platform.');
        }
    });

    it('â‰¥25 auth/security config keys present using config_key column', function (): void {
        $count = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where(function ($q) {
                $q->where('config_key', 'like', 'auth.%')
                  ->orWhere('config_key', 'like', 'security.%');
            })
            ->count();
        expect($count)->toBeGreaterThanOrEqual(25);
    });

});

// ============================================================================
// 26: Retention / cleanup jobs
// ============================================================================

describe('[GATE-26] Retention â€” cleanup jobs', function (): void {

    it('SessionCleanupJob and OtpChallengeCleanupJob classes exist', function (): void {
        expect(class_exists(\App\Modules\Identity\Infrastructure\Jobs\SessionCleanupJob::class))->toBeTrue();
        expect(class_exists(\App\Modules\Identity\Infrastructure\Jobs\OtpChallengeCleanupJob::class))->toBeTrue();
    });

    it('SessionCleanupJob preserves active sessions within retention window', function (): void {
        gateUser('+26771900100', '246810', 'gate-100');
        gateLogin('+26771900100', '246810', 'gate-100');

        $activeBefore = DB::connection('identity')->table('identity.sessions')
            ->where('status', 'active')->count();

        app(\App\Modules\Identity\Infrastructure\Jobs\SessionCleanupJob::class)
            ->handle(
                app(\App\Modules\Identity\Domain\Services\ConfigurationSecurityService::class),
                app(\App\Modules\Platform\Domain\Contracts\EventPublisherInterface::class),
            );

        $activeAfter = DB::connection('identity')->table('identity.sessions')
            ->where('status', 'active')->count();

        expect($activeAfter)->toBe($activeBefore);
    });

});

// ============================================================================
// GATE-SEED: All 25 configuration keys seeded
// ============================================================================

describe('[GATE-SEED] 25 configuration keys seeded', function (): void {

    it('all 25 seeded auth/security config keys present in platform.configuration_values', function (): void {
        $expectedKeys = [
            'auth.step_up_threshold',
            'auth.step_up_threshold_currency',
            'auth.session.inactivity_ttl_admin',
            'auth.session.inactivity_ttl_customer',
            'auth.session.inactivity_ttl_agent',
            'auth.refresh_token.ttl',
            'auth.refresh_token.rotation_enabled',
            'auth.refresh_token.reuse_detection_enabled',
            'auth.otp.ttl',
            'auth.otp.max_attempts',
            'auth.otp.resend_cooldown',
            'auth.otp.max_sends_per_hour',
            'auth.pin.length',
            'auth.pin.reject_sequential',
            'auth.pin.reject_repeated',
            'auth.pin.reject_common',
            'auth.lockout.max_failed_attempts',
            'auth.lockout.initial_duration',
            'auth.lockout.progressive_enabled',
            'auth.mfa.admin.totp_enabled',
            'auth.mfa.admin.fido2_enabled',
            'auth.mfa.admin.sms_enabled',
            'auth.biometric.enabled',
            'auth.otp.provider',
            'security.ip_audit.hash_algorithm',
        ];

        $missing = [];
        foreach ($expectedKeys as $key) {
            if (!DB::connection('platform')->table('platform.configuration_values')->where('config_key', $key)->exists()) {
                $missing[] = $key;
            }
        }
        expect($missing)->toBeEmpty('Missing config keys: ' . implode(', ', $missing));
    });

});

// ============================================================================
// GATE-27: Configuration Admin API — Phase 15 (T143-T151)
// ============================================================================

describe('[GATE-27] Configuration Admin API — Phase 15', function (): void {

    it('GET admin/platform/configuration/security returns 25 keys for ADMIN actor (AC-022)', function (): void {
        gateUser('+26779270001', '246810', 'gate-cfg-1', 'ADMIN');
        $session = gateLogin('+26779270001', '246810', 'gate-cfg-1');
        $token   = $session['access_token'];

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);

        $resp->assertStatus(200);
        expect($resp->json('data.total'))->toBe(25);
        expect($resp->json('data.configuration'))->toHaveCount(25);
    });

    it('is_sensitive key returns [REDACTED] in GET response — no secret exposure (AC-022)', function (): void {
        gateUser('+26779270002', '246810', 'gate-cfg-2', 'ADMIN');
        $session = gateLogin('+26779270002', '246810', 'gate-cfg-2');
        $token   = $session['access_token'];

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);

        $sensitive = collect($resp->json('data.configuration'))
            ->firstWhere('key', 'security.ip_audit.hash_algorithm');

        expect($sensitive['value'])->toBe('[REDACTED]');
        expect($sensitive['is_sensitive'])->toBeTrue();
    });

    it('non-ADMIN actor is rejected with 403 AUTHORIZATION_002 on GET (authorization boundary)', function (): void {
        gateUser('+26779270003', '246810', 'gate-cfg-3', 'CUSTOMER');
        $session = gateLogin('+26779270003', '246810', 'gate-cfg-3');
        $token   = $session['access_token'];

        $resp = $this->getJson('/v1/admin/platform/configuration/security', [
            'Authorization' => "Bearer {$token}",
        ]);

        $resp->assertStatus(403);
        expect($resp->json('error.code'))->toBe('AUTHORIZATION_002');
    });

    it('valid PUT persists new value and emits identity.config.updated audit event (AC-023, BR-021)', function (): void {
        gateUser('+26779270004', '246810', 'gate-cfg-4', 'ADMIN');
        $session = gateLogin('+26779270004', '246810', 'gate-cfg-4');
        $token   = $session['access_token'];

        $version = (function (): string {
            $row = DB::connection('platform')
                ->table('platform.configuration_values')
                ->where('config_key', 'auth.otp.max_sends_per_hour')
                ->first(['updated_at']);
            return $row ? \Carbon\Carbon::parse($row->updated_at)->toIso8601String() : now()->toIso8601String();
        })();

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.otp.max_sends_per_hour',
            ['value' => '8', 'version' => $version, 'reason' => 'gate-27 test'],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]
        );

        $resp->assertStatus(200);
        expect($resp->json('data.key'))->toBe('auth.otp.max_sends_per_hour');
        expect($resp->json('data.new_value'))->toBe('8');

        // Audit event emitted
        $event = DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.config.updated')
            ->where('resource_id', 'auth.otp.max_sends_per_hour')
            ->orderByDesc('occurred_at')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->outcome)->toBe('success');
        expect($event->resource_type)->toBe('configuration');
    });

    it('stale version rejected with 409 IDENTITY_016 — optimistic concurrency enforced (AC-025)', function (): void {
        gateUser('+26779270005', '246810', 'gate-cfg-5', 'ADMIN');
        $session = gateLogin('+26779270005', '246810', 'gate-cfg-5');
        $token   = $session['access_token'];

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.otp.resend_cooldown',
            ['value' => '90', 'version' => '2020-01-01T00:00:00+00:00'],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]
        );

        $resp->assertStatus(409);
        expect($resp->json('error.code'))->toBe('IDENTITY_016');
    });

    it('value below security minimum rejected with 400 IDENTITY_001 (AC-024)', function (): void {
        gateUser('+26779270006', '246810', 'gate-cfg-6', 'ADMIN');
        $session = gateLogin('+26779270006', '246810', 'gate-cfg-6');
        $token   = $session['access_token'];

        $version = (function (): string {
            $row = DB::connection('platform')
                ->table('platform.configuration_values')
                ->where('config_key', 'auth.pin.length')
                ->first(['updated_at']);
            return $row ? \Carbon\Carbon::parse($row->updated_at)->toIso8601String() : now()->toIso8601String();
        })();

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.pin.length',
            ['value' => '4', 'version' => $version],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]
        );

        $resp->assertStatus(400);
        expect($resp->json('error.code'))->toBe('IDENTITY_001');
    });

    it('unknown key returns 404 IDENTITY_006 (AC-026)', function (): void {
        gateUser('+26779270007', '246810', 'gate-cfg-7', 'ADMIN');
        $session = gateLogin('+26779270007', '246810', 'gate-cfg-7');
        $token   = $session['access_token'];

        $resp = $this->putJson(
            '/v1/admin/platform/configuration/security/auth.does.not.exist',
            ['value' => 'foo', 'version' => now()->toIso8601String()],
            ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]
        );

        $resp->assertStatus(404);
        expect($resp->json('error.code'))->toBe('IDENTITY_006');
    });

});

// ============================================================================
// GATE-PERF: P95 latency targets
// ============================================================================

describe('[GATE-PERF] Performance â€” P95 latency targets', function () use (&$capturedGateOtp): void {

    it('login P95 â‰¤ 500 ms over 10 sequential samples', function (): void {
        gateUser('+26771900120', '246810', 'gate-120');
        $times = [];
        for ($i = 0; $i < 10; $i++) {
            $start = microtime(true);
            $this->postJson('/v1/auth/login', [
                'identifier'         => '+26771900120',
                'credential_type'    => 'pin',
                'credential'         => '246810',
                'device_fingerprint' => 'gate-120',
            ]);
            $times[] = (microtime(true) - $start) * 1000;
        }
        sort($times);
        $p95 = $times[(int) ceil(0.95 * count($times)) - 1];
        expect($p95)->toBeLessThan(500);
    });

    it('token refresh P95 â‰¤ 200 ms over 10 sequential samples', function (): void {
        gateUser('+26771900121', '246810', 'gate-121');
        $session      = gateLogin('+26771900121', '246810', 'gate-121');
        $refreshToken = $session['refresh_token'];
        $times        = [];

        for ($i = 0; $i < 10; $i++) {
            $start        = microtime(true);
            $resp         = $this->postJson('/v1/auth/token/refresh', ['refresh_token' => $refreshToken]);
            $times[]      = (microtime(true) - $start) * 1000;
            $refreshToken = (string) $resp->json('data.refresh_token');
        }
        sort($times);
        $p95 = $times[(int) ceil(0.95 * count($times)) - 1];
        expect($p95)->toBeLessThan(200);
    });

    it('OTP verify P95 â‰¤ 500 ms over 5 sequential samples', function () use (&$capturedGateOtp): void {
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $mobile = '+267199020' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $reg    = $this->postJson('/v1/auth/register', ['mobile_number' => $mobile, 'actor_type' => 'CUSTOMER']);
            $cid    = $reg->json('data.challenge_id');
            $otp    = $capturedGateOtp;
            $start  = microtime(true);
            $this->postJson('/v1/auth/otp/verify', ['challenge_id' => $cid, 'otp' => $otp]);
            $times[] = (microtime(true) - $start) * 1000;
        }
        sort($times);
        $p95 = $times[(int) ceil(0.95 * count($times)) - 1];
        expect($p95)->toBeLessThan(500);
    });

});

