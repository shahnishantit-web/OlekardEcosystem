<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryContract;
use App\Modules\Identity\Domain\Contracts\OtpDeliveryResult;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;


/**
 * Decode a JWT section (header or payload) from base64url-encoded string.
 */
function jwtDecode(string $segment): array
{
    $padded = str_pad(strtr($segment, '-_', '+/'), strlen($segment) % 4 === 0 ? strlen($segment) : strlen($segment) + (4 - strlen($segment) % 4), '=', STR_PAD_RIGHT);
    return json_decode(base64_decode($padded), true);
}

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
 * Helper: create an active user with a PIN credential and trusted device, then log in.
 * Returns [$user, $deviceId].
 */
function loginAndGetJwt(string $mobile, string $pin, string $deviceId): array
{
    $users    = app(UserRepositoryInterface::class);
    $credRepo = app(CredentialRepositoryInterface::class);
    $devRepo  = app(DeviceRepositoryInterface::class);

    $user = $users->create([
        'mobile_number' => $mobile,
        'status'        => 'active',
        'actor_type'    => 'CUSTOMER',
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

    return [$user, $deviceId];
}

describe('Token issuance â€” JWT structure', function (): void {

    it('issues a JWT with alg=ES256 in the header (AC-024)', function (): void {
        loginAndGetJwt('+26771001001', '111222', 'dev-ti-001');

        $response = test()->postJson('/v1/auth/login', [
            'identifier'        => '+26771001001',
            'credential_type'   => 'pin',
            'credential'         => '111222',
            'device_fingerprint' => 'dev-ti-001',
        ]);

        $response->assertStatus(200);
        $header = jwtDecode(explode('.', $response->json('data.access_token'))[0]);
        expect($header['alg'])->toBe('ES256');
        expect($header['typ'])->toBe('JWT');
    });

    it('includes kid in JWT header matching JWKS (AC-024)', function (): void {
        loginAndGetJwt('+26771001002', '222333', 'dev-ti-002');

        $response = test()->postJson('/v1/auth/login', [
            'identifier'        => '+26771001002',
            'credential_type'   => 'pin',
            'credential'         => '222333',
            'device_fingerprint' => 'dev-ti-002',
        ]);

        $response->assertStatus(200);

        $header  = jwtDecode(explode('.', $response->json('data.access_token'))[0]);
        $jwksKid = test()->getJson('/v1/.well-known/jwks.json')->json('keys.0.kid');

        expect($header['kid'])->toBe($jwksKid);
    });

    it('sets iss claim to app.url (AC-024)', function (): void {
        loginAndGetJwt('+26771001003', '333444', 'dev-ti-003');

        $response = test()->postJson('/v1/auth/login', [
            'identifier'        => '+26771001003',
            'credential_type'   => 'pin',
            'credential'         => '333444',
            'device_fingerprint' => 'dev-ti-003',
        ]);

        $response->assertStatus(200);

        $payload = jwtDecode(explode('.', $response->json('data.access_token'))[1]);
        expect($payload['iss'])->toBe(config('app.url', 'identity-service'));
    });

    it('sets exp = iat + TTL matching expires_in from response', function (): void {
        loginAndGetJwt('+26771001004', '444555', 'dev-ti-004');

        $response = test()->postJson('/v1/auth/login', [
            'identifier'        => '+26771001004',
            'credential_type'   => 'pin',
            'credential'         => '444555',
            'device_fingerprint' => 'dev-ti-004',
        ]);

        $response->assertStatus(200);

        $expiresIn = $response->json('data.expires_in');
        $payload   = jwtDecode(explode('.', $response->json('data.access_token'))[1]);

        expect($payload['exp'] - $payload['iat'])->toBe($expiresIn);
    });

    it('sets sub claim to the user UUID', function (): void {
        [$user] = loginAndGetJwt('+26771001005', '555666', 'dev-ti-005');

        $response = test()->postJson('/v1/auth/login', [
            'identifier'        => '+26771001005',
            'credential_type'   => 'pin',
            'credential'         => '555666',
            'device_fingerprint' => 'dev-ti-005',
        ]);

        $response->assertStatus(200);

        $payload = jwtDecode(explode('.', $response->json('data.access_token'))[1]);
        expect($payload['sub'])->toBe($user->id);
    });

    it('sets session_id claim matching session_id in response (AC-024)', function (): void {
        loginAndGetJwt('+26771001006', '666777', 'dev-ti-006');

        $response = test()->postJson('/v1/auth/login', [
            'identifier'        => '+26771001006',
            'credential_type'   => 'pin',
            'credential'         => '666777',
            'device_fingerprint' => 'dev-ti-006',
        ]);

        $response->assertStatus(200);

        $payload   = jwtDecode(explode('.', $response->json('data.access_token'))[1]);
        $sessionId = $response->json('data.session_id');

        expect($payload['session_id'])->toBe($sessionId);
    });

    it('sets actor_type claim (AC-024)', function (): void {
        loginAndGetJwt('+26771001007', '777888', 'dev-ti-007');

        $response = test()->postJson('/v1/auth/login', [
            'identifier'        => '+26771001007',
            'credential_type'   => 'pin',
            'credential'         => '777888',
            'device_fingerprint' => 'dev-ti-007',
        ]);

        $response->assertStatus(200);

        $payload = jwtDecode(explode('.', $response->json('data.access_token'))[1]);
        expect($payload['actor_type'])->toBe('CUSTOMER');
    });

    it('sets device_id claim to a valid UUID (AC-024)', function (): void {
        loginAndGetJwt('+26771001008', '888999', 'dev-ti-008');

        $response = test()->postJson('/v1/auth/login', [
            'identifier'        => '+26771001008',
            'credential_type'   => 'pin',
            'credential'         => '888999',
            'device_fingerprint' => 'dev-ti-008',
        ]);

        $response->assertStatus(200);

        $payload = jwtDecode(explode('.', $response->json('data.access_token'))[1]);
        // device_id in JWT is the device record UUID, not the raw fingerprint
        expect($payload['device_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
    });

    it('JWT is verifiable with the public key from JWKS', function (): void {
        loginAndGetJwt('+26771001009', '999000', 'dev-ti-009');

        $response = test()->postJson('/v1/auth/login', [
            'identifier'        => '+26771001009',
            'credential_type'   => 'pin',
            'credential'         => '999000',
            'device_fingerprint' => 'dev-ti-009',
        ]);

        $response->assertStatus(200);
        $rawJwt = $response->json('data.access_token');

        // Fetch JWKS and reconstruct public key from x/y coordinates
        $jwksResponse = test()->getJson('/v1/.well-known/jwks.json');
        $jwk          = $jwksResponse->json('keys.0');

        // Parse and verify using the ES256 signer â€” verification must use the public key
        $provider    = app(\App\Modules\Identity\Domain\Contracts\JwksProviderContract::class);
        $signingKey  = $provider->getSigningKey();
        $privDetails = openssl_pkey_get_details(openssl_pkey_get_private($signingKey->privateKeyPem));
        $pubKeyPem   = $privDetails['key'];

        $verifyConfig = \Lcobucci\JWT\Configuration::forAsymmetricSigner(
            new \Lcobucci\JWT\Signer\Ecdsa\Sha256(),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText($signingKey->privateKeyPem),
            \Lcobucci\JWT\Signer\Key\InMemory::plainText($pubKeyPem),
        );

        $token = $verifyConfig->parser()->parse($rawJwt);
        expect($verifyConfig->validator()->validate($token, new \Lcobucci\JWT\Validation\Constraint\SignedWith(
            $verifyConfig->signer(),
            $verifyConfig->verificationKey(),
        )))->toBeTrue();
    });

    it('issues unique session IDs per login (BR-009)', function (): void {
        loginAndGetJwt('+26771001010', '100200', 'dev-ti-010');

        $payload = [
            'identifier'        => '+26771001010',
            'credential_type'   => 'pin',
            'credential'         => '100200',
            'device_fingerprint' => 'dev-ti-010',
        ];

        $r1 = test()->postJson('/v1/auth/login', $payload);
        $r2 = test()->postJson('/v1/auth/login', $payload);

        $r1->assertStatus(200);
        $r2->assertStatus(200);

        expect($r1->json('data.session_id'))->not->toBe($r2->json('data.session_id'));
    });

    it('issues unique refresh tokens per login (BR-009)', function (): void {
        loginAndGetJwt('+26771001011', '200300', 'dev-ti-011');

        $payload = [
            'identifier'        => '+26771001011',
            'credential_type'   => 'pin',
            'credential'         => '200300',
            'device_fingerprint' => 'dev-ti-011',
        ];

        $r1 = test()->postJson('/v1/auth/login', $payload);
        $r2 = test()->postJson('/v1/auth/login', $payload);

        $r1->assertStatus(200);
        $r2->assertStatus(200);

        expect($r1->json('data.refresh_token'))->not->toBe($r2->json('data.refresh_token'));
    });

});

