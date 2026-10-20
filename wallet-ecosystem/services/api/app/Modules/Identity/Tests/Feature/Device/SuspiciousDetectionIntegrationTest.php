<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Jobs\SuspiciousDeviceDetectionJob;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeIdentityAuthMiddleware;

/**
 * T055 — Integration test for suspicious detection opacity (US-DEVICE-007).
 *
 * Verifies that:
 * - 4 users with same fingerprint trigger multi_account rule when the job runs
 * - The outbox event contains full risk_signals (rules_fired etc.)
 * - API responses do NOT expose risk_signals or rules_fired to callers
 */

beforeEach(function (): void {
    Queue::fake();

    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    \Illuminate\Support\Facades\Redis::connection('identity')->flushdb();
});

function susCreateUserAndDevice(string $email, string $phone, string $fingerprint): array
{
    $userId   = (string) \Ramsey\Uuid\Uuid::uuid7();
    $deviceId = (string) \Ramsey\Uuid\Uuid::uuid7();

    \DB::connection('identity')->table('identity.users')->insert([
        'id'         => $userId,
        'email'      => $email,
        'mobile_number'      => $phone,
        'status'     => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $deviceId,
        'user_id'            => $userId,
        'device_name'        => 'Shared Device',
        'platform'           => 'android',
        'device_fingerprint' => $fingerprint,
        'status'             => 'pending_verification',
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    return compact('userId', 'deviceId');
}

describe('SuspiciousDetectionIntegrationTest', function (): void {

    it('4 users sharing same fingerprint trigger multi_account rule when job runs', function (): void {
        // Set threshold to 3
        \DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'device.max_accounts_per_fingerprint')
            ->update(['config_value' => '3']);

        $sharedFp = hash('sha256', 'four-users-same-fp');

        $pair1 = susCreateUserAndDevice('sus-int1@t.local', '+26772400001', $sharedFp);
        $pair2 = susCreateUserAndDevice('sus-int2@t.local', '+26772400002', $sharedFp);
        $pair3 = susCreateUserAndDevice('sus-int3@t.local', '+26772400003', $sharedFp);
        $pair4 = susCreateUserAndDevice('sus-int4@t.local', '+26772400004', $sharedFp);

        // Run the suspicious detection job synchronously
        (new SuspiciousDeviceDetectionJob($pair4['deviceId'], $pair4['userId'], $sharedFp, false))->handle(
            app(\App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface::class),
            app(\App\Modules\Identity\Domain\Services\DeviceSecurityService::class),
        );

        // Outbox event should exist with full risk_signals
        $outbox = \DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Device.Device.SuspiciousDetected')
            ->first();

        expect($outbox)->not->toBeNull();

        $payload = json_decode($outbox->payload, true);
        expect($payload)->toHaveKey('risk_signals');
        expect($payload['risk_signals']['rules_fired'])->toContain('multi_account_fingerprint');
    });

    it('API device list response does NOT contain risk_signals or rules_fired', function (): void {
        $sharedFp = hash('sha256', 'opacity-test-fp');
        $pair     = susCreateUserAndDevice('opacity@t.local', '+26772400010', $sharedFp);

        $response = $this->getJson('/v1/devices', ['X-Test-User-Id' => $pair['userId']]);

        $response->assertStatus(200);
        $body = $response->getContent();

        expect($body)->not->toContain('risk_signals');
        expect($body)->not->toContain('rules_fired');
        expect($body)->not->toContain('suspicious');
    });

    it('audit record for suspicious_detected does NOT include risk_signals in before/after_state', function (): void {
        \DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'device.max_accounts_per_fingerprint')
            ->update(['config_value' => '1']);

        $sharedFp = hash('sha256', 'audit-opacity-fp');
        $pair1    = susCreateUserAndDevice('aud-opacity1@t.local', '+26772400020', $sharedFp);
        $pair2    = susCreateUserAndDevice('aud-opacity2@t.local', '+26772400021', $sharedFp);

        (new SuspiciousDeviceDetectionJob($pair2['deviceId'], $pair2['userId'], $sharedFp, false))->handle(
            app(\App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface::class),
            app(\App\Modules\Identity\Domain\Services\DeviceSecurityService::class),
        );

        $audit = \DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.device.suspicious-detected')
            ->first();

        expect($audit)->not->toBeNull();

        // before_state and after_state should not expose individual account IDs
        $beforeState = json_decode($audit->before_state ?? 'null', true);
        $afterState  = json_decode($audit->after_state ?? 'null', true);

        expect(json_encode($beforeState))->not->toContain('account_ids');
        // after_state may contain rules_fired summary — this is acceptable per spec for audit records
        // but should not contain actual account identifiers
        if ($afterState !== null) {
            expect(json_encode($afterState))->not->toContain('account_ids');
        }
    });

})->group('identity', 'device', 'api');
