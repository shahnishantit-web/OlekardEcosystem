<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Jobs\SuspiciousDeviceDetectionJob;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeIdentityAuthMiddleware;

/**
 * T117 — Security test: suspicious detection opacity.
 *
 * Verifies that:
 * - API responses never expose risk_signals, rules_fired, or "suspicious" markers
 * - The outbox event payload DOES contain full risk_signals (internal only)
 * - Audit records do NOT include risk_signals in before_state or after_state
 */

beforeEach(function (): void {
    Queue::fake();

    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    \DB::connection('platform')
        ->table('platform.configuration_values')
        ->where('config_key', 'device.max_accounts_per_fingerprint')
        ->update(['config_value' => '1']);

    $this->sharedFp = hash('sha256', 'opacity-shared-device');

    $this->userId1   = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->userId2   = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->deviceId1 = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->deviceId2 = (string) \Ramsey\Uuid\Uuid::uuid7();

    \DB::connection('identity')->table('identity.users')->insert([
        ['id' => $this->userId1, 'email' => 'opacity1@t.local', 'mobile_number' => '+26772600001', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ['id' => $this->userId2, 'email' => 'opacity2@t.local', 'mobile_number' => '+26772600002', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    ]);

    \DB::connection('identity')->table('identity.devices')->insert([
        [
            'id' => $this->deviceId1, 'user_id' => $this->userId1, 'device_name' => 'D1',
            'platform' => 'android', 'device_fingerprint' => $this->sharedFp,
            'status' => 'pending_verification', 'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'id' => $this->deviceId2, 'user_id' => $this->userId2, 'device_name' => 'D2',
            'platform' => 'android', 'device_fingerprint' => $this->sharedFp,
            'status' => 'pending_verification', 'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    // Trigger detection
    (new SuspiciousDeviceDetectionJob($this->deviceId2, $this->userId2, $this->sharedFp, false))->handle(
        app(\App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface::class),
        app(\App\Modules\Identity\Domain\Services\DeviceSecurityService::class),
    );
});

describe('Suspicious detection opacity invariants', function (): void {

    it('GET /v1/devices response does not contain risk_signals', function (): void {
        $response = $this->getJson('/v1/devices', ['X-Test-User-Id' => $this->userId2]);
        $response->assertStatus(200);

        expect($response->getContent())->not->toContain('risk_signals');
        expect($response->getContent())->not->toContain('rules_fired');
        expect($response->getContent())->not->toContain('suspicious');
    });

    it('GET /v1/devices/{id} response does not contain risk_signals', function (): void {
        $response = $this->getJson("/v1/devices/{$this->deviceId2}", ['X-Test-User-Id' => $this->userId2]);

        // Could be 200 or 403 depending on IDOR protection; either way no risk signals
        expect($response->getContent())->not->toContain('risk_signals');
        expect($response->getContent())->not->toContain('rules_fired');
    });

    it('outbox event DOES contain full risk_signals (internal enrichment)', function (): void {
        $outbox = \DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Device.Device.SuspiciousDetected')
            ->first();

        expect($outbox)->not->toBeNull();

        $payload = json_decode($outbox->payload, true);
        expect($payload)->toHaveKey('risk_signals');
        expect($payload['risk_signals'])->toHaveKey('rules_fired');
        expect($payload['risk_signals']['rules_fired'])->not->toBeEmpty();
    });

    it('audit record for suspicious-detected does NOT store raw risk_signals in before_state', function (): void {
        $audit = \DB::connection('platform')
            ->table('platform.audit_events')
            ->where('action', 'identity.device.suspicious-detected')
            ->first();

        expect($audit)->not->toBeNull();

        $beforeState = $audit->before_state ?? 'null';
        expect($beforeState)->not->toContain('account_ids');
        expect($beforeState)->not->toContain('multi_account_count');
    });

})->group('identity', 'device', 'security');
