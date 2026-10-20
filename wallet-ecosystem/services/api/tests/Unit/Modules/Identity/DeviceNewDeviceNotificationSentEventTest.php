<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Identity;

use App\Modules\Identity\Domain\Events\DeviceNewDeviceNotificationSentEvent;
use Tests\Support\UnitTestCase;

final class DeviceNewDeviceNotificationSentEventTest extends UnitTestCase
{
    public function test_payload_contains_all_br012_required_fields(): void
    {
        $deviceId               = '019401aa-0000-7000-0000-000000000001';
        $userId                 = '019401bb-0000-7000-0000-000000000002';
        $acknowledgmentExpiresAt = '2026-09-03T14:00:00+00:00';
        $deviceName             = 'My Pixel 9';
        $platform               = 'android';

        $event = new DeviceNewDeviceNotificationSentEvent(
            deviceId:               $deviceId,
            actorId:                null,
            correlationId:          '019401cc-0000-7000-0000-000000000003',
            userId:                 $userId,
            acknowledgmentExpiresAt: $acknowledgmentExpiresAt,
            deviceName:             $deviceName,
            platform:               $platform,
        );

        $payload = $event->payload();

        // BR-012: notification MUST include device name, platform, timestamp
        $this->assertSame($deviceId, $payload['device_id']);
        $this->assertSame($userId, $payload['user_id']);
        $this->assertSame($platform, $payload['platform']);
        $this->assertSame($deviceName, $payload['device_name']);
        $this->assertSame($acknowledgmentExpiresAt, $payload['acknowledgment_expires_at']);
    }

    public function test_event_name_and_aggregate_type(): void
    {
        $event = new DeviceNewDeviceNotificationSentEvent(
            deviceId:               '019401aa-0000-7000-0000-000000000001',
            actorId:                null,
            correlationId:          '019401cc-0000-7000-0000-000000000003',
            userId:                 '019401bb-0000-7000-0000-000000000002',
            acknowledgmentExpiresAt: '2026-09-03T14:00:00+00:00',
            deviceName:             'Test Device',
            platform:               'ios',
        );

        $this->assertSame('Device.Device.NewDeviceNotificationSent', $event->eventName());
        $this->assertSame('identity.device', $event->aggregateType());
    }
}
