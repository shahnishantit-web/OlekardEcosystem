<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Commands\DetectDeviceChangeCommand;
use App\Modules\Identity\Application\Handlers\DetectDeviceChangeHandler;
use App\Modules\Identity\Domain\Services\DeviceSecurityService;
use App\Modules\Identity\Domain\Services\DeviceFingerprintService;
use App\Modules\Identity\Infrastructure\Repositories\EloquentDeviceRepository;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeIdentityAuthMiddleware;

beforeEach(function (): void {
    Queue::fake();

    $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);

    $this->userId   = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->deviceId = (string) \Ramsey\Uuid\Uuid::uuid7();
    $this->fingerprint = hash('sha256', 'original-device-data');

    \DB::connection('identity')->table('identity.users')->insert([
        'id'         => $this->userId,
        'email'      => 'change-detect@test.local',
        'mobile_number'      => '+26772100001',
        'status'     => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    \DB::connection('identity')->table('identity.devices')->insert([
        'id'                 => $this->deviceId,
        'user_id'            => $this->userId,
        'device_name'        => 'Trusted Phone',
        'platform'           => 'android',
        'device_fingerprint' => $this->fingerprint,
        'status'             => 'trusted',
        'trusted_at'         => now(),
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);
});

function changeDetectCmd(string $deviceId, string $hash, string $userId): DetectDeviceChangeCommand
{
    return new DetectDeviceChangeCommand(
        deviceId:               $deviceId,
        userId:                 $userId,
        submittedFingerprintHash: $hash,
        actorType:              'CUSTOMER',
        correlationId:          (string) \Ramsey\Uuid\Uuid::uuid7(),
        ipHash:                 str_repeat('0', 64),
    );
}

describe('DetectDeviceChangeHandler — detection logic', function (): void {

    it('returns false when submitted hash matches the stored fingerprint (no change)', function (): void {
        $handler = app(DetectDeviceChangeHandler::class);
        $result  = $handler->handle(changeDetectCmd($this->deviceId, $this->fingerprint, $this->userId));

        expect($result)->toBeFalse();
    });

    it('returns true when submitted hash differs from stored fingerprint (change detected)', function (): void {
        $newHash = hash('sha256', 'different-device');

        $handler = app(DetectDeviceChangeHandler::class);
        $result  = $handler->handle(changeDetectCmd($this->deviceId, $newHash, $this->userId));

        expect($result)->toBeTrue();
    });

    it('demotes device to pending_verification when change detected', function (): void {
        $newHash = hash('sha256', 'changed-device-data');

        $handler = app(DetectDeviceChangeHandler::class);
        $handler->handle(changeDetectCmd($this->deviceId, $newHash, $this->userId));

        $device = \DB::connection('identity')->table('identity.devices')->where('id', $this->deviceId)->first();
        expect($device->status)->toBe('pending_verification');
    });

    it('creates a change_detected security event when fingerprint differs', function (): void {
        $newHash = hash('sha256', 'security-event-test');

        $handler = app(DetectDeviceChangeHandler::class);
        $handler->handle(changeDetectCmd($this->deviceId, $newHash, $this->userId));

        $event = \DB::connection('identity')
            ->table('identity.device_security_events')
            ->where('device_id', $this->deviceId)
            ->where('event_type', 'change_detected')
            ->first();

        expect($event)->not->toBeNull();
        expect($event->fingerprint_hash)->toBe($newHash);
    });

    it('publishes Device.Device.ChangeDetected outbox event on change', function (): void {
        $newHash = hash('sha256', 'outbox-event-test');

        $handler = app(DetectDeviceChangeHandler::class);
        $handler->handle(changeDetectCmd($this->deviceId, $newHash, $this->userId));

        $outbox = \DB::connection('platform')
            ->table('platform.outbox_events')
            ->where('event_name', 'Device.Device.ChangeDetected')
            ->first();

        expect($outbox)->not->toBeNull();
    });

    it('returns false when change_detection_enabled config is false', function (): void {
        \DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', 'device.change_detection_enabled')
            ->update(['config_value' => 'false']);

        $newHash = hash('sha256', 'would-be-change');

        $handler = app(DetectDeviceChangeHandler::class);
        $result  = $handler->handle(changeDetectCmd($this->deviceId, $newHash, $this->userId));

        expect($result)->toBeFalse();

        // Device should remain trusted (not demoted)
        $device = \DB::connection('identity')->table('identity.devices')->where('id', $this->deviceId)->first();
        expect($device->status)->toBe('trusted');
    });

    it('returns false when device not found', function (): void {
        $handler = app(DetectDeviceChangeHandler::class);
        $result  = $handler->handle(changeDetectCmd((string) \Ramsey\Uuid\Uuid::uuid7(), str_repeat('b', 64), $this->userId));

        expect($result)->toBeFalse();
    });

})->group('identity', 'device', 'unit');
