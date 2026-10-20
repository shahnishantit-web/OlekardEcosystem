<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;

beforeEach(function (): void {
    Redis::connection('identity')->flushdb();
});

describe('GET /v1/.well-known/jwks.json', function (): void {

    it('returns 200 with a JWK Set structure', function (): void {
        $response = $this->getJson('/v1/.well-known/jwks.json');

        $response->assertStatus(200)
            ->assertJsonStructure(['keys'])
            ->assertJsonPath('keys.0.kty', 'EC')
            ->assertJsonPath('keys.0.crv', 'P-256')
            ->assertJsonPath('keys.0.use', 'sig')
            ->assertJsonPath('keys.0.alg', 'ES256');
    });

    it('includes kid in each JWK', function (): void {
        $response = $this->getJson('/v1/.well-known/jwks.json');

        $response->assertStatus(200);
        $keys = $response->json('keys');
        expect($keys)->not->toBeEmpty();
        foreach ($keys as $key) {
            expect($key)->toHaveKey('kid');
            expect(strlen($key['kid']))->toBe(12);
        }
    });

    it('does not expose the private key d-parameter', function (): void {
        $response = $this->getJson('/v1/.well-known/jwks.json');

        $response->assertStatus(200);
        $body = $response->getContent();
        expect($body)->not->toContain('"d"');

        foreach ($response->json('keys') as $key) {
            expect($key)->not->toHaveKey('d');
        }
    });

    it('includes x and y coordinates as base64url strings', function (): void {
        $response = $this->getJson('/v1/.well-known/jwks.json');

        $response->assertStatus(200);
        foreach ($response->json('keys') as $key) {
            expect($key)->toHaveKeys(['x', 'y']);
            // base64url uses - and _ instead of + and /, and no padding =
            expect($key['x'])->toMatch('/^[A-Za-z0-9\-_]+$/');
            expect($key['y'])->toMatch('/^[A-Za-z0-9\-_]+$/');
            expect($key['x'])->not->toContain('=');
            expect($key['y'])->not->toContain('=');
        }
    });

    it('returns Cache-Control: public, max-age=300 header', function (): void {
        $response = $this->getJson('/v1/.well-known/jwks.json');

        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control');
        expect($cacheControl)->toContain('public');
        expect($cacheControl)->toContain('max-age=300');
    });

    it('serves from Redis cache on second request', function (): void {
        // Prime cache
        $first  = $this->getJson('/v1/.well-known/jwks.json');
        $second = $this->getJson('/v1/.well-known/jwks.json');

        $first->assertStatus(200);
        $second->assertStatus(200);

        expect($second->json('keys'))->toBe($first->json('keys'));

        $cached = Redis::connection('identity')->get('identity:jwks');
        expect($cached)->not->toBeNull();
        $decoded = json_decode($cached, true);
        expect($decoded)->toHaveKey('keys');
    });

    it('includes old key during rotation when IDENTITY_JWT_OLD_PUBLIC_KEY is set', function (): void {
        // Generate a second EC key to simulate the old key
        $oldKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($oldKey === false) {
            test()->markTestSkipped('openssl EC key generation not supported on this platform');
        }
        openssl_pkey_export($oldKey, $oldPrivPem);
        if (empty($oldPrivPem)) {
            test()->markTestSkipped('openssl EC key export not supported on this platform');
        }

        // Extract public key PEM for the old key
        $oldDetails = openssl_pkey_get_details($oldKey);
        $oldPubPem  = $oldDetails['key'];

        // Set the env var as public PEM (rotation provides only the public key)
        putenv("IDENTITY_JWT_OLD_PUBLIC_KEY={$oldPubPem}");
        // Flush cache so the new env is picked up
        Redis::connection('identity')->del('identity:jwks');

        $response = $this->getJson('/v1/.well-known/jwks.json');
        $response->assertStatus(200);

        $keys = $response->json('keys');
        expect(count($keys))->toBeGreaterThanOrEqual(2);

        $kids = array_column($keys, 'kid');
        expect(count(array_unique($kids)))->toBe(count($kids)); // all kids are unique

        // Clean up
        putenv('IDENTITY_JWT_OLD_PUBLIC_KEY');
        Redis::connection('identity')->del('identity:jwks');
    });

    it('kid for active key matches kid in issued JWT header (AC-024)', function (): void {
        // Get the JWKS-published kid for the active key
        $jwksResponse = $this->getJson('/v1/.well-known/jwks.json');
        $jwksResponse->assertStatus(200);
        $publishedKid = $jwksResponse->json('keys.0.kid');

        // Perform a login so we get a real JWT
        $users   = app(\App\Modules\Identity\Domain\Contracts\UserRepositoryInterface::class);
        $credRepo = app(\App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface::class);

        $user = $users->create([
            'mobile_number' => '+26771000099',
            'status'        => 'active',
            'actor_type'    => 'CUSTOMER',
        ]);
        $credRepo->create([
            'user_id'         => $user->id,
            'credential_type' => 'pin',
            'credential_hash' => \Illuminate\Support\Facades\Hash::make('654321'),
            'status'          => 'active',
        ]);
        app(\App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface::class)->create([
            'user_id'            => $user->id,
            'device_fingerprint' => hash('sha256', 'jwks-test-device'),
            'status'             => 'trusted',
            'platform'           => 'android',
        ]);

        $loginResponse = $this->postJson('/v1/auth/login', [
            'identifier'         => '+26771000099',
            'credential_type'    => 'pin',
            'credential'         => '654321',
            'device_fingerprint' => 'jwks-test-device',
        ]);

        $loginResponse->assertStatus(200);
        $rawJwt = $loginResponse->json('data.access_token');

        // Decode header without verifying signature (just base64url decode)
        $parts  = explode('.', $rawJwt);
        $header = json_decode(base64_decode(str_pad(strtr($parts[0], '-_', '+/'), strlen($parts[0]) % 4 == 0 ? strlen($parts[0]) : strlen($parts[0]) + (4 - strlen($parts[0]) % 4), '=', STR_PAD_RIGHT)), true);

        expect($header['kid'])->toBe($publishedKid);
        expect($header['alg'])->toBe('ES256');
    });

});

