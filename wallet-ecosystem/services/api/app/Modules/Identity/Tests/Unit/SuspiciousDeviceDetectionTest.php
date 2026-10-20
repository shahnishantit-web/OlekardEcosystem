<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Services\DeviceSecurityService;
use App\Modules\Identity\Infrastructure\Models\Device;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeIdentityAuthMiddleware;

/**
 * T054 — Unit/integration tests for SuspiciousDeviceDetection (US-DEVICE-007).
 *
 * Tests DeviceSecurityService::detectSuspicious rules:
 * - Rule 1: multi_account_fingerprint (same fingerprint used by > max_accounts_per_fingerprint users)
 * - Rule 2: emulator_detected
 * - Rule 3: abnormal_change_rate
 * - Config gate: suspicious_detection_enabled=false → nothing fires
 */

beforeEach(function (): void {
    Queue::fake();

    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    // Shared fingerprint for multi-account test
    $this->sharedFingerprint = hash('sha256', 'shared-device-fingerprint');
    \Illuminate\Support\Facades\Redis::connection('identity')->flushdb();
});

function createTestUser(string $email, string $phone): string
{
    $id = (string) \Ramsey\Uuid\Uuid::uuid7();
    \DB::connection('identity')->table('identity.users')->insert([
        'id'         => $id,
        'email'      => $email,
        'mobile_number'      => $phone,
        'status'     => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return $id;
}

function createDeviceWithFingerprint(string $userId, string $fingerprint, string $platform = 'android'): string
{
    $id = (string) \Ramsey\Uuid\Uuid::uuid7();
    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $id,
        'user_id'            => $userId,
        'device_name'        => 'Suspicious Device',
        'platform'           => $platform,
        'device_fingerprint' => $fingerprint,
        'status'             => 'pending_verification',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
    return $id;
}

describe('DeviceSecurityService::detectSuspicious', function (): void {

    it('fires multi_account_fingerprint rule when same fingerprint used by > max_accounts users', function (): void {
        // Set max to 2
        \DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'device.max_accounts_per_fingerprint')
            ->update(['config_value' => '2']);

        $sharedFp = hash('sha256', 'shared-fp-test');

        $u1 = createTestUser('sus1@t.local', '+26772300001');
        $u2 = createTestUser('sus2@t.local', '+26772300002');
        $u3 = createTestUser('sus3@t.local', '+26772300003');

        $d1 = createDeviceWithFingerprint($u1, $sharedFp);
        createDeviceWithFingerprint($u2, $sharedFp);
        createDeviceWithFingerprint($u3, $sharedFp);

        $device1 = Device::find($d1);

        /** @var DeviceSecurityService $service */
        $service = app(DeviceSecurityService::class);
        $service->detectSuspicious($device1, $u1, false, (string) \Ramsey\Uuid\Uuid::uuid7());

        $event = \DB::connection('identity')
            ->table('identity.device_security_events')
            ->where('device_id', $d1)
            ->where('event_type', 'suspicious_detected')
            ->first();

        expect($event)->not->toBeNull();
        $signals = json_decode($event->risk_signals, true);
        expect($signals['rules_fired'])->toContain('multi_account_fingerprint');
    });

    it('fires emulator_detected rule when isEmulator=true', function (): void {
        $userId   = createTestUser('sus-emu@t.local', '+26772300010');
        $deviceId = createDeviceWithFingerprint($userId, hash('sha256', 'emu-fp'));
        $device   = Device::find($deviceId);

        /** @var DeviceSecurityService $service */
        $service = app(DeviceSecurityService::class);
        $service->detectSuspicious($device, $userId, isEmulator: true, correlationId: (string) \Ramsey\Uuid\Uuid::uuid7());

        $event = \DB::connection('identity')
            ->table('identity.device_security_events')
            ->where('device_id', $deviceId)
            ->where('event_type', 'suspicious_detected')
            ->first();

        expect($event)->not->toBeNull();
        $signals = json_decode($event->risk_signals, true);
        expect($signals['rules_fired'])->toContain('emulator_detected');
    });

    it('fires abnormal_change_rate rule when change count exceeds max_device_changes_per_window', function (): void {
        \DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'device.max_device_changes_per_window')
            ->update(['config_value' => '2']);

        $userId   = createTestUser('sus-rate@t.local', '+26772300020');
        $deviceId = createDeviceWithFingerprint($userId, hash('sha256', 'rate-fp'));
        $device   = Device::find($deviceId);

        // Insert 3 change_detected events within the window
        for ($i = 0; $i < 3; $i++) {
            \DB::connection('identity')->table('identity.device_security_events')->insert([
                'id'             => (string) \Ramsey\Uuid\Uuid::uuid7(),
                'device_id'      => $deviceId,
                'user_id'        => $userId,
                'event_type'     => 'change_detected',
                'correlation_id' => (string) \Ramsey\Uuid\Uuid::uuid7(),
                'created_at'     => now()->subMinutes($i),
                'updated_at'     => now(),
            ]);
        }

        /** @var DeviceSecurityService $service */
        $service = app(DeviceSecurityService::class);
        $service->detectSuspicious($device, $userId, false, (string) \Ramsey\Uuid\Uuid::uuid7());

        $event = \DB::connection('identity')
            ->table('identity.device_security_events')
            ->where('device_id', $deviceId)
            ->where('event_type', 'suspicious_detected')
            ->first();

        expect($event)->not->toBeNull();
        $signals = json_decode($event->risk_signals, true);
        expect($signals['rules_fired'])->toContain('abnormal_change_rate');
    });

    it('does nothing when suspicious_detection_enabled=false', function (): void {
        \DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'device.suspicious_detection_enabled')
            ->update(['config_value' => 'false']);

        $userId   = createTestUser('sus-disabled@t.local', '+26772300030');
        $deviceId = createDeviceWithFingerprint($userId, hash('sha256', 'disabled-fp'));
        $device   = Device::find($deviceId);

        /** @var DeviceSecurityService $service */
        $service = app(DeviceSecurityService::class);
        $service->detectSuspicious($device, $userId, isEmulator: true, correlationId: (string) \Ramsey\Uuid\Uuid::uuid7());

        $event = \DB::connection('identity')
            ->table('identity.device_security_events')
            ->where('device_id', $deviceId)
            ->where('event_type', 'suspicious_detected')
            ->first();

        expect($event)->toBeNull();
    });

    it('risk_signals contain rules_fired key when event is emitted', function (): void {
        $userId   = createTestUser('sus-signals@t.local', '+26772300040');
        $deviceId = createDeviceWithFingerprint($userId, hash('sha256', 'signals-fp'));
        $device   = Device::find($deviceId);

        /** @var DeviceSecurityService $service */
        $service = app(DeviceSecurityService::class);
        $service->detectSuspicious($device, $userId, isEmulator: true, correlationId: (string) \Ramsey\Uuid\Uuid::uuid7());

        $outbox = \DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Device.Device.SuspiciousDetected')
            ->first();

        expect($outbox)->not->toBeNull();
        $payload = json_decode($outbox->payload, true);
        expect($payload)->toHaveKey('risk_signals');
        expect($payload['risk_signals'])->toHaveKey('rules_fired');
    });

})->group('identity', 'device', 'unit');
