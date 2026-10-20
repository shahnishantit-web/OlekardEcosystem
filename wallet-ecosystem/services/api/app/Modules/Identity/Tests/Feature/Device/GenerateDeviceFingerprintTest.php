<?php

declare(strict_types=1);

use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    $this->userId = (string) \Ramsey\Uuid\Uuid::uuid7();

    \DB::connection('identity')->table('identity.users')->insert([
        'id'         => $this->userId,
        'email'      => 'fp-test@test.local',
        'mobile_number'      => '+26771300001',
        'status'     => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function androidAttrs(): array
{
    return [
        'os_version'     => '14',
        'hardware_model' => 'Pixel 9 Pro XL',
        'android_id'     => 'a1b2c3d4e5f67890',
        'screen_resolution' => '1440x3120',
        'locale'         => 'en-US',
    ];
}

function iosAttrs(): array
{
    return [
        'os_version'     => '17.5',
        'hardware_model' => 'iPhone16,1',
        'idfv'           => '11111111-2222-3333-4444-555555555555',
    ];
}

function webAttrs(): array
{
    return [
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
        'timezone'   => 'Africa/Gaborone',
    ];
}

describe('POST /v1/devices/fingerprint', function (): void {

    it('returns 200 with fingerprint_hash for valid android attributes', function (): void {
        $response = $this->postJson('/v1/devices/fingerprint', [
            'platform'   => 'android',
            'attributes' => androidAttrs(),
        ], ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['fingerprint_hash']]);

        expect(strlen($response->json('data.fingerprint_hash')))->toBe(64);
    });

    it('returns 200 with fingerprint_hash for valid ios attributes', function (): void {
        $response = $this->postJson('/v1/devices/fingerprint', [
            'platform'   => 'ios',
            'attributes' => iosAttrs(),
        ], ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        expect(strlen($response->json('data.fingerprint_hash')))->toBe(64);
    });

    it('returns 200 with fingerprint_hash for valid web attributes', function (): void {
        $response = $this->postJson('/v1/devices/fingerprint', [
            'platform'   => 'web',
            'attributes' => webAttrs(),
        ], ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200);
        expect(strlen($response->json('data.fingerprint_hash')))->toBe(64);
    });

    it('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/v1/devices/fingerprint', [
            'platform'   => 'android',
            'attributes' => androidAttrs(),
        ]);

        $response->assertStatus(401);
    });

    it('returns 400 INSUFFICIENT_ENTROPY when attributes are minimal', function (): void {
        $response = $this->postJson('/v1/devices/fingerprint', [
            'platform'   => 'android',
            'attributes' => ['android_id' => 'aaaa'],
        ], ['X-Test-User-Id' => $this->userId]);

        // Fingerprint generation with insufficient entropy → 400
        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('INSUFFICIENT_ENTROPY');
    });

    it('does NOT include raw attribute values in the response', function (): void {
        $response = $this->postJson('/v1/devices/fingerprint', [
            'platform'   => 'android',
            'attributes' => androidAttrs(),
        ], ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(200);
        $body = $response->getContent();

        expect($body)->not->toContain('Pixel 9 Pro XL');
        expect($body)->not->toContain('a1b2c3d4e5f67890');
        expect($body)->not->toContain('attributes');
    });

    it('produces the same hash on repeated calls (determinism)', function (): void {
        $payload = ['platform' => 'android', 'attributes' => androidAttrs()];
        $headers = ['X-Test-User-Id' => $this->userId];

        $r1 = $this->postJson('/v1/devices/fingerprint', $payload, $headers);
        $r2 = $this->postJson('/v1/devices/fingerprint', $payload, $headers);

        $r1->assertStatus(200);
        $r2->assertStatus(200);

        expect($r1->json('data.fingerprint_hash'))->toBe($r2->json('data.fingerprint_hash'));
    });

    it('creates an audit record for fingerprint generation', function (): void {
        $this->postJson('/v1/devices/fingerprint', [
            'platform'   => 'android',
            'attributes' => androidAttrs(),
        ], ['X-Test-User-Id' => $this->userId]);

        $audit = \DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.device.fingerprint-generated')
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->outcome)->toBe('success');
    });

})->group('identity', 'device', 'api');
