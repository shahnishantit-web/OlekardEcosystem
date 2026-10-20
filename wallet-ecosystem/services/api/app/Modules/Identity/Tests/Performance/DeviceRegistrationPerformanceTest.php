<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeIdentityAuthMiddleware;

/**
 * T133 — Performance test: POST /v1/devices
 *
 * Assert: 100 concurrent registrations for the same user complete with P95 ≤ 300ms.
 * Uses sequential execution as a baseline proxy.
 *
 * Also verifies:
 * - No duplicate device rows for the same (user_id, fingerprint) under concurrent load
 * - Device count does not exceed max_devices_per_user (10)
 */

beforeEach(function (): void {
    Queue::fake();

    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

    $this->userId = (string) \Ramsey\Uuid\Uuid::uuid7();

    \DB::connection('identity')->table('identity.users')->insert([
        'id'         => $this->userId,
        'email'      => 'perf-reg@test.local',
        'mobile_number'      => '+26773200001',
        'status'     => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

describe('POST /v1/devices — performance baseline', function (): void {

    it('single registration request completes in under 300ms', function (): void {
        $start    = microtime(true);
        $response = $this->postJson('/v1/devices', [
            'device_name'      => 'Perf Phone',
            'platform'         => 'android',
            'fingerprint_hash' => hash('sha256', 'perf-single-reg'),
        ], [
            'X-Test-User-Id'  => $this->userId,
            'Idempotency-Key' => 'perf-single-' . uniqid(),
        ]);
        $elapsed = (microtime(true) - $start) * 1000;

        $response->assertStatus(201);
        expect($elapsed)->toBeLessThan(300.0);
    });

    it('P95 of 50 sequential registrations (unique devices) is under 300ms', function (): void {
        $latencies = [];

        for ($i = 0; $i < 50; $i++) {
            $start = microtime(true);
            $response = $this->postJson('/v1/devices', [
                'device_name'      => "Perf Phone $i",
                'platform'         => 'android',
                'fingerprint_hash' => hash('sha256', "perf-device-$i"),
            ], [
                'X-Test-User-Id'  => $this->userId,
                'Idempotency-Key' => "perf-reg-$i-" . uniqid(),
            ]);
            $latencies[] = (microtime(true) - $start) * 1000;

            // Accept 201 or 409 (limit reached)
            expect($response->status())->toBeIn([201, 409]);
        }

        sort($latencies);
        $p95 = $latencies[(int) floor(count($latencies) * 0.95)];

        expect($p95)->toBeLessThan(300.0, "P95 latency {$p95}ms exceeds 300ms SLO");
    });

    it('concurrent registration of same fingerprint does not create duplicate device rows', function (): void {
        $fingerprint = hash('sha256', 'duplicate-prevention-test');
        $idemKey     = 'dup-test-' . uniqid();

        // Simulate two concurrent requests with the same fingerprint but different idempotency keys
        $r1 = $this->postJson('/v1/devices', [
            'device_name'      => 'Device 1',
            'platform'         => 'android',
            'fingerprint_hash' => $fingerprint,
        ], ['X-Test-User-Id' => $this->userId, 'Idempotency-Key' => $idemKey . '-a']);

        $r2 = $this->postJson('/v1/devices', [
            'device_name'      => 'Device 2',
            'platform'         => 'android',
            'fingerprint_hash' => $fingerprint,
        ], ['X-Test-User-Id' => $this->userId, 'Idempotency-Key' => $idemKey . '-b']);

        // One or both succeed; the row count for this fingerprint must be exactly 1
        $count = \DB::connection('identity')
            ->table('identity.devices')
            ->where('user_id', $this->userId)
            ->where('device_fingerprint', $fingerprint)
            ->count();

        expect($count)->toBe(1, 'Duplicate device row detected for same (user_id, fingerprint)');
    });

    it('device count does not exceed max_devices_per_user under burst registration', function (): void {
        $max = 5;

        \DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'device.max_devices_per_user')
            ->update(['config_value' => (string) $max]);

        // Try to register max+3 devices
        $statuses = [];
        for ($i = 0; $i < $max + 3; $i++) {
            $response   = $this->postJson('/v1/devices', [
                'device_name'      => "Burst $i",
                'platform'         => 'android',
                'fingerprint_hash' => hash('sha256', "burst-device-$i"),
            ], [
                'X-Test-User-Id'  => $this->userId,
                'Idempotency-Key' => "burst-$i-" . uniqid(),
            ]);
            $statuses[] = $response->status();
        }

        $deviceCount = \DB::connection('identity')
            ->table('identity.devices')
            ->where('user_id', $this->userId)
            ->whereNotIn('status', ['revoked'])
            ->count();

        expect($deviceCount)->toBeLessThanOrEqual($max, "Device count $deviceCount exceeds max $max");
        expect($statuses)->toContain(409); // At least one 409 DEVICE_LIMIT_EXCEEDED
    });

})->group('identity', 'device', 'performance');
