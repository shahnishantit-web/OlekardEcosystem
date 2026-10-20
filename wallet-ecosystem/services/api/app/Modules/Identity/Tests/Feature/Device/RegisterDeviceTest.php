<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    Queue::fake();

    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    $this->userId = (string) \Ramsey\Uuid\Uuid::uuid7();

    \DB::connection('identity')->table('identity.users')->insert([
        'id'         => $this->userId,
        'email'      => 'reg-test@test.local',
        'mobile_number'      => '+26771400001',
        'status'     => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function validFingerprint(): string
{
    return str_repeat('a', 64);
}

function registerHeaders(string $userId, ?string $idemKey = null): array
{
    return [
        'X-Test-User-Id'  => $userId,
        'Idempotency-Key' => $idemKey ?? 'reg-' . uniqid(),
    ];
}

describe('POST /v1/devices', function (): void {

    it('returns 201 with pending_verification for a new device', function (): void {
        $response = $this->postJson('/v1/devices', [
            'device_name'      => 'My Pixel 9',
            'platform'         => 'android',
            'fingerprint_hash' => validFingerprint(),
        ], registerHeaders($this->userId));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['device_id', 'status']]);

        expect($response->json('data.status'))->toBe('pending_verification');
    });

    it('returns 200 for idempotent replay with same Idempotency-Key', function (): void {
        $idemKey = 'idem-' . uniqid();
        $payload = [
            'device_name'      => 'My Pixel 9',
            'platform'         => 'android',
            'fingerprint_hash' => validFingerprint(),
        ];

        $r1 = $this->postJson('/v1/devices', $payload, registerHeaders($this->userId, $idemKey));
        $r2 = $this->postJson('/v1/devices', $payload, registerHeaders($this->userId, $idemKey));

        $r1->assertStatus(201);
        $r2->assertStatus(200);
        expect($r1->json('data.device_id'))->toBe($r2->json('data.device_id'));
    });

    it('returns 200 when same fingerprint already exists for user', function (): void {
        $fingerprint = validFingerprint();

        $this->postJson('/v1/devices', [
            'device_name'      => 'Phone 1',
            'platform'         => 'android',
            'fingerprint_hash' => $fingerprint,
        ], registerHeaders($this->userId));

        $r2 = $this->postJson('/v1/devices', [
            'device_name'      => 'Phone 2 Same Fingerprint',
            'platform'         => 'android',
            'fingerprint_hash' => $fingerprint,
        ], registerHeaders($this->userId));

        $r2->assertStatus(200);
    });

    it('returns 409 DEVICE_LIMIT_EXCEEDED when device limit is reached', function (): void {
        // Seed max_devices_per_user = 1
        \DB::connection('platform')->table('platform.configuration_values')
            ->where('config_key', 'device.max_devices_per_user')
            ->update(['config_value' => '1']);

        // Register first device
        $this->postJson('/v1/devices', [
            'device_name'      => 'First',
            'platform'         => 'android',
            'fingerprint_hash' => str_repeat('b', 64),
        ], registerHeaders($this->userId));

        // Second device hits limit
        $r2 = $this->postJson('/v1/devices', [
            'device_name'      => 'Second',
            'platform'         => 'android',
            'fingerprint_hash' => str_repeat('c', 64),
        ], registerHeaders($this->userId));

        $r2->assertStatus(409);
        expect($r2->json('error.code'))->toBe('DEVICE_LIMIT_EXCEEDED');
    });

    it('returns 422 when fingerprint_hash is not 64-char hex', function (): void {
        $response = $this->postJson('/v1/devices', [
            'device_name'      => 'My Phone',
            'platform'         => 'android',
            'fingerprint_hash' => 'short-invalid-hash',
        ], registerHeaders($this->userId));

        $response->assertStatus(422);
    });

    it('returns 401 when no auth header provided', function (): void {
        $response = $this->postJson('/v1/devices', [
            'device_name'      => 'My Phone',
            'platform'         => 'android',
            'fingerprint_hash' => validFingerprint(),
        ]);

        $response->assertStatus(401);
    });

    it('creates an outbox event for new device registration', function (): void {
        $this->postJson('/v1/devices', [
            'device_name'      => 'My Pixel 9',
            'platform'         => 'android',
            'fingerprint_hash' => validFingerprint(),
        ], registerHeaders($this->userId));

        $outbox = \DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Device.Device.Registered')
            ->first();

        expect($outbox)->not->toBeNull();
    });

    it('creates an audit record for new device registration', function (): void {
        $this->postJson('/v1/devices', [
            'device_name'      => 'My Pixel 9',
            'platform'         => 'android',
            'fingerprint_hash' => validFingerprint(),
        ], registerHeaders($this->userId));

        $audit = \DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.device.registered')
            ->first();

        expect($audit)->not->toBeNull();
        expect($audit->outcome)->toBe('success');
    });

    it('returns 400 when Idempotency-Key header is missing', function (): void {
        $response = $this->postJson('/v1/devices', [
            'device_name'      => 'My Phone',
            'platform'         => 'android',
            'fingerprint_hash' => validFingerprint(),
        ], ['X-Test-User-Id' => $this->userId]);

        $response->assertStatus(400);
        expect($response->json('error.code'))->toBe('MISSING_IDEMPOTENCY_KEY');
    });

})->group('identity', 'device', 'api');
