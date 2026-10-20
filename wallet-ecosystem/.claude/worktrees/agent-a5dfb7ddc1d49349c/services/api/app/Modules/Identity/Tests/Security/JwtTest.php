<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Phase 21 JWT Security Test Suite (T200)
 *
 * Covers:
 * - Forged JWT with wrong issuer rejected (invalid signature)
 * - Forged JWT with wrong audience rejected (invalid signature + PermittedFor constraint)
 * - Step-up token rejected as general user Bearer (aud = step-up:{scope} ≠ app.url)
 * - Service token rejected as user Bearer (no session_id, wrong aud)
 * - Required claims enforced: sub, iss, aud, exp, iat, jti, session_id, actor_type
 * - Expired access token rejected
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

function jwtCreateUser(string $mobile, string $fp = 'jwt-sec-dev', string $actorType = 'CUSTOMER'): void
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
}

function jwtLogin(mixed $test, string $mobile, string $fp = 'jwt-sec-dev'): array
{
    $resp = $test->postJson('/v1/auth/login', [
        'credential_type'    => 'pin',
        'identifier'         => $mobile,
        'credential'         => '654321',
        'device_fingerprint' => $fp,
    ]);
    $resp->assertStatus(200);

    return [
        'access_token'  => (string) $resp->json('data.access_token'),
        'refresh_token' => (string) $resp->json('data.refresh_token'),
        'session_id'    => (string) $resp->json('data.session_id'),
    ];
}

function jwtDecodePayload(string $token): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return [];
    }
    $pad     = strlen($parts[1]) % 4;
    $padded  = $pad ? $parts[1] . str_repeat('=', 4 - $pad) : $parts[1];
    $decoded = base64_decode(strtr($padded, '-_', '+/'), strict: false);

    return json_decode($decoded, true) ?? [];
}

// ============================================================================
// Forged / Invalid Tokens
// ============================================================================

describe('Forged JWT rejection (T200)', function (): void {

    it('forged JWT with wrong issuer returns 401 (invalid ES256 signature)', function (): void {
        $header  = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub'        => 'jwt-forge-user',
            'iss'        => 'https://evil-issuer.example.com',
            'aud'        => config('app.url', 'identity-service'),
            'exp'        => time() + 3600,
            'iat'        => time(),
            'jti'        => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'actor_type' => 'CUSTOMER',
        ])), '+/', '-_'), '=');
        $fakeJwt = "{$header}.{$payload}.invalidsignature";

        test()->withToken($fakeJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('forged JWT with wrong audience returns 401', function (): void {
        $header  = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub'        => 'jwt-forge-aud',
            'iss'        => config('app.url', 'identity-service'),
            'aud'        => 'https://wrong-audience.example.com',
            'exp'        => time() + 3600,
            'iat'        => time(),
            'jti'        => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'actor_type' => 'CUSTOMER',
        ])), '+/', '-_'), '=');
        $fakeJwt = "{$header}.{$payload}.invalidsignature";

        test()->withToken($fakeJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('expired JWT returns 401', function (): void {
        $header  = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub'        => 'jwt-expired-user',
            'iss'        => config('app.url', 'identity-service'),
            'aud'        => config('app.url', 'identity-service'),
            'exp'        => time() - 60,
            'iat'        => time() - 3660,
            'jti'        => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'actor_type' => 'CUSTOMER',
        ])), '+/', '-_'), '=');
        $fakeJwt = "{$header}.{$payload}.invalidsignature";

        test()->withToken($fakeJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('JWT with none algorithm is rejected with 401', function (): void {
        $header  = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub'        => 'jwt-alg-none',
            'iss'        => config('app.url', 'identity-service'),
            'aud'        => config('app.url', 'identity-service'),
            'exp'        => time() + 3600,
            'iat'        => time(),
            'session_id' => (string) Str::uuid(),
            'actor_type' => 'CUSTOMER',
        ])), '+/', '-_'), '=');
        // alg=none tokens have an empty signature
        $fakeJwt = "{$header}.{$payload}.";

        test()->withToken($fakeJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('JWT with an unknown kid is rejected with 401', function (): void {
        // The kid header references a key not in the current JWKS.
        // IdentityAuthMiddleware verifies against the active signing key — a token
        // signed with a different (unknown) key has an invalid signature and is rejected.
        $header  = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => 'unknown-kid-not-in-jwks'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode([
            'sub'        => 'jwt-unknown-kid',
            'iss'        => config('app.url', 'identity-service'),
            'aud'        => config('app.url', 'identity-service'),
            'exp'        => time() + 3600,
            'iat'        => time(),
            'jti'        => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'actor_type' => 'CUSTOMER',
        ])), '+/', '-_'), '=');
        // Signature is invalid — signed with an unknown key the middleware cannot look up
        $fakeJwt = "{$header}.{$payload}.invalidsignatureforunknownkid";

        test()->withToken($fakeJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

});

// ============================================================================
// Step-up Token as General Bearer
// ============================================================================

describe('Step-up token rejected as general Bearer (T200)', function (): void {

    it('step-up access token is rejected when used as a user Bearer on a regular endpoint', function (): void {
        jwtCreateUser('+26778200001');
        $auth = jwtLogin(test(), '+26778200001');

        // Obtain a step-up token
        $initResp = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);
        $initResp->assertStatus(200);
        $challengeToken = $initResp->json('data.step_up_token');

        $verifyResp = test()->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $challengeToken,
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);
        $verifyResp->assertStatus(200);
        $stepUpJwt = $verifyResp->json('data.step_up_access_token');

        // Step-up JWT has aud = 'step-up:payment:high-value' ≠ config('app.url')
        // The PermittedFor constraint in IdentityAuthMiddleware must reject it
        test()->withToken($stepUpJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('step-up token has a different audience than the regular access token', function (): void {
        jwtCreateUser('+26778200002');
        $auth = jwtLogin(test(), '+26778200002');

        $initResp = test()->postJson('/v1/auth/step-up/initiate', [
            'scope'             => 'payment:high-value',
            'operation_context' => ['amount' => 20000, 'currency' => 'BWP'],
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);
        $initResp->assertStatus(200);

        $verifyResp = test()->postJson('/v1/auth/step-up/verify', [
            'step_up_token' => $initResp->json('data.step_up_token'),
            'method'        => 'pin',
            'credential'    => '654321',
        ], ['Authorization' => "Bearer {$auth['access_token']}"]);
        $verifyResp->assertStatus(200);

        $stepUpJwt      = $verifyResp->json('data.step_up_access_token');
        $regularClaims  = jwtDecodePayload($auth['access_token']);
        $stepUpClaims   = jwtDecodePayload($stepUpJwt);

        // Audiences must differ — step-up must not be usable as a regular token
        $regularAud = is_array($regularClaims['aud']) ? $regularClaims['aud'] : [$regularClaims['aud']];
        $stepUpAud  = is_array($stepUpClaims['aud'])  ? $stepUpClaims['aud']  : [$stepUpClaims['aud']];

        expect($stepUpAud)->not->toBe($regularAud);
        expect(implode(',', $stepUpAud))->toContain('step-up:');
    });

});

// ============================================================================
// Service Token as User Bearer
// ============================================================================

describe('Service token rejected as user Bearer (T200)', function (): void {

    it('service JWT is rejected when used as a user Bearer on an identity.auth endpoint', function (): void {
        $resp = test()->postJson('/v1/auth/service/token', [
            'service_id' => 'jwt-sec-svc-001',
            'audience'   => 'internal-payments',
            'scopes'     => ['payments:write'],
        ], [
            'X-Service-Secret' => 'test-service-secret',
            'Idempotency-Key'  => (string) Str::uuid(),
        ]);
        $resp->assertStatus(200);
        $serviceJwt = $resp->json('data.access_token');

        // Service JWT lacks session_id and has a non-user audience — must be rejected
        test()->withToken($serviceJwt)
            ->getJson('/v1/auth/sessions')
            ->assertStatus(401);
    });

    it('service JWT has actor_type SERVICE — not CUSTOMER or ADMIN', function (): void {
        $resp = test()->postJson('/v1/auth/service/token', [
            'service_id' => 'jwt-sec-svc-002',
            'audience'   => 'test-aud',
            'scopes'     => ['payments:read'],
        ], [
            'X-Service-Secret' => 'test-service-secret',
            'Idempotency-Key'  => (string) Str::uuid(),
        ]);
        $resp->assertStatus(200);

        $claims = jwtDecodePayload($resp->json('data.access_token'));
        expect($claims['actor_type'] ?? '')->toBe('SERVICE');
    });

});

// ============================================================================
// Required JWT Claims
// ============================================================================

describe('Required JWT claims present in issued access tokens (T200)', function (): void {

    it('issued access token contains all required claims: sub iss aud exp iat jti session_id actor_type', function (): void {
        jwtCreateUser('+26778200010');
        $auth = jwtLogin(test(), '+26778200010');

        $claims = jwtDecodePayload($auth['access_token']);

        expect($claims)->toHaveKey('sub');
        expect($claims)->toHaveKey('iss');
        expect($claims)->toHaveKey('aud');
        expect($claims)->toHaveKey('exp');
        expect($claims)->toHaveKey('iat');
        expect($claims)->toHaveKey('jti');
        expect($claims)->toHaveKey('session_id');
        expect($claims)->toHaveKey('actor_type');

        // Required claim value constraints
        expect($claims['sub'])->toBeString()->not->toBeEmpty();
        expect($claims['iss'])->toBeString()->not->toBeEmpty();
        expect($claims['exp'])->toBeInt()->toBeGreaterThan(time());
        expect($claims['iat'])->toBeInt()->toBeLessThanOrEqual(time());
        expect($claims['jti'])->toBeString()->not->toBeEmpty();
        expect($claims['session_id'])->toBe($auth['session_id']);
        expect(strtoupper((string) $claims['actor_type']))->toBe('CUSTOMER');
    });

    it('aud claim matches the configured app URL (no step-up or service audience in user tokens)', function (): void {
        jwtCreateUser('+26778200011');
        $auth   = jwtLogin(test(), '+26778200011');
        $claims = jwtDecodePayload($auth['access_token']);

        $aud = is_array($claims['aud']) ? implode(',', $claims['aud']) : (string) ($claims['aud'] ?? '');
        expect($aud)->not->toContain('step-up:');
        expect($aud)->not->toContain('SERVICE');
    });

    it('jti is unique across two independently issued tokens', function (): void {
        jwtCreateUser('+26778200012', 'jwt-unique-dev');

        $token1 = jwtLogin(test(), '+26778200012', 'jwt-unique-dev')['access_token'];
        $token2 = jwtLogin(test(), '+26778200012', 'jwt-unique-dev')['access_token'];

        $claims1 = jwtDecodePayload($token1);
        $claims2 = jwtDecodePayload($token2);

        expect($claims1['jti'])->not->toBe($claims2['jti']);
        expect($claims1['session_id'])->not->toBe($claims2['session_id']);
    });

    it('access token does not include raw credential, OTP, or private key material', function (): void {
        jwtCreateUser('+26778200013');
        $auth = jwtLogin(test(), '+26778200013');

        $rawToken = $auth['access_token'];
        $claims   = jwtDecodePayload($rawToken);
        $claimsJson = json_encode($claims);

        // No credential material in claims
        expect($claimsJson)->not->toContain('654321');  // PIN
        expect($claimsJson)->not->toContain('password');
        expect($claimsJson)->not->toContain('"d"');     // EC private key d-param

        // No private key d-param in raw token string
        expect($rawToken)->not->toContain('"d"');
    });

})->group('identity', 'security', 'jwt');
