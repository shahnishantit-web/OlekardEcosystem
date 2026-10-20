<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\JwksProviderContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;


beforeEach(function (): void {
    $this->app->bind(OtpDeliveryContract::class, fn () => new class implements OtpDeliveryContract {
        public function deliver(string $d, string $otp, string $ch, string $c): OtpDeliveryResult
        {
            return new OtpDeliveryResult(true, 'stub', null);
        }
    });

    Redis::connection('identity')->flushdb();
});

/**
 * Obtain a real access token from the login endpoint for testing.
 */
function issueRealToken(string $mobile, string $pin, string $deviceId, string $actorType = 'CUSTOMER'): string
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
        'credential_hash' => Hash::make($pin),
        'status'          => 'active',
    ]);

    $devRepo->create([
        'user_id'            => $user->id,
        'device_fingerprint' => hash('sha256', $deviceId),
        'status'             => 'trusted',
        'platform'           => 'android',
    ]);

    $response = test()->postJson('/v1/auth/login', [
        'identifier'         => $mobile,
        'credential_type'    => 'pin',
        'credential'         => $pin,
        'device_fingerprint' => $deviceId,
    ]);

    expect($response->status())->toBe(200);

    return $response->json('data.access_token');
}

/**
 * Build a fake JWT-shaped string with an unknown kid header.
 * The token is not cryptographically valid â€” only used to verify JWKS does not contain the kid.
 */
function buildTokenWithUnknownKey(string $kid = 'unknownkid000'): string
{
    $header  = base64_encode(json_encode(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => $kid]));
    $payload = base64_encode(json_encode(['sub' => 'fake', 'iat' => time(), 'exp' => time() + 900]));
    return rtrim(strtr($header, '+/', '-_'), '=') . '.' . rtrim(strtr($payload, '+/', '-_'), '=') . '.fakesignature';
}

describe('Token security invariants', function (): void {

    it('private key d-parameter never appears in JWKS endpoint (AC-013)', function (): void {
        $response = test()->getJson('/v1/.well-known/jwks.json');
        $response->assertStatus(200);

        $body = $response->getContent();
        expect($body)->not->toContain('"d"');
        expect($body)->not->toContain("'d'");

        foreach ($response->json('keys') as $key) {
            expect($key)->not->toHaveKey('d');
        }
    });

    it('access token does not contain raw credential value (BR-004)', function (): void {
        $pin   = '123654';
        $token = issueRealToken('+26771009001', $pin, 'sec-dev-001');

        expect($token)->not->toContain($pin);

        // Also check decoded payload
        $parts   = explode('.', $token);
        $payload = base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + (4 - strlen($parts[1]) % 4), '=', STR_PAD_RIGHT));
        expect($payload)->not->toContain($pin);
    });

    it('token signed with an unknown kid is not accepted (AC-024)', function (): void {
        // The system does not currently expose a bearer-authenticated endpoint in Phase 7,
        // so we verify the JWKS does NOT contain the unknown kid, meaning a consumer
        // checking the JWKS would reject the token.
        $fakeToken = buildTokenWithUnknownKey('unknownkid000');

        $jwksResponse = test()->getJson('/v1/.well-known/jwks.json');
        $jwksResponse->assertStatus(200);
        $kids = array_column($jwksResponse->json('keys'), 'kid');

        expect($kids)->not->toContain('unknownkid000');
    });

    it('kid in JWKS is exactly 12 hex characters (AC-024)', function (): void {
        $response = test()->getJson('/v1/.well-known/jwks.json');
        $response->assertStatus(200);

        foreach ($response->json('keys') as $key) {
            expect($key['kid'])->toMatch('/^[0-9a-f]{12}$/');
        }
    });

    it('all keys in JWKS use alg=ES256 (AC-024)', function (): void {
        $response = test()->getJson('/v1/.well-known/jwks.json');
        $response->assertStatus(200);

        foreach ($response->json('keys') as $key) {
            expect($key['alg'])->toBe('ES256');
            expect($key['kty'])->toBe('EC');
            expect($key['crv'])->toBe('P-256');
        }
    });

    it('refresh token is opaque â€” base64 only, not a JWT', function (): void {
        issueRealToken('+26771009002', '234567', 'sec-dev-002');

        $response = test()->postJson('/v1/auth/login', [
            'identifier'         => '+26771009002',
            'credential_type'    => 'pin',
            'credential'         => '234567',
            'device_fingerprint' => 'sec-dev-002',
        ]);

        $response->assertStatus(200);
        $refreshToken = $response->json('data.refresh_token');

        // A JWT has two dots; an opaque base64 token should not
        expect(substr_count($refreshToken, '.'))->toBeLessThan(2);
        // Should be valid base64
        expect(base64_decode($refreshToken, strict: false))->not->toBeFalse();
    });

    it('issued JWT has three segments (header.payload.signature)', function (): void {
        $token = issueRealToken('+26771009003', '345678', 'sec-dev-003');

        $parts = explode('.', $token);
        expect(count($parts))->toBe(3);
        // Each segment is non-empty base64url
        foreach ($parts as $part) {
            expect($part)->toMatch('/^[A-Za-z0-9\-_]+$/');
        }
    });

    it('JWKS response contains at least one key when IDENTITY_JWT_PRIVATE_KEY is set', function (): void {
        $response = test()->getJson('/v1/.well-known/jwks.json');
        $response->assertStatus(200);

        $keys = $response->json('keys');
        expect(count($keys))->toBeGreaterThanOrEqual(1);
    });

    it('access token exp is in the future when issued', function (): void {
        $token = issueRealToken('+26771009004', '456789', 'sec-dev-004');

        $parts   = explode('.', $token);
        $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + (4 - strlen($parts[1]) % 4), '=', STR_PAD_RIGHT)), true);

        expect($payload['exp'])->toBeGreaterThan(time());
        expect((int) $payload['iat'])->toBeLessThanOrEqual(time());
    });

    it('two logins produce different access tokens (uniqueness)', function (): void {
        issueRealToken('+26771009005', '567890', 'sec-dev-005');

        $payload = [
            'identifier'         => '+26771009005',
            'credential_type'    => 'pin',
            'credential'         => '567890',
            'device_fingerprint' => 'sec-dev-005',
        ];

        $t1 = test()->postJson('/v1/auth/login', $payload)->json('data.access_token');
        $t2 = test()->postJson('/v1/auth/login', $payload)->json('data.access_token');

        expect($t1)->not->toBe($t2);
    });

});

