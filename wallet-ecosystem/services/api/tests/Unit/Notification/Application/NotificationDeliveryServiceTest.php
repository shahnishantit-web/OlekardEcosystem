<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Application;

use App\Modules\Notification\Application\Jobs\DeliverInAppNotificationJob;
use App\Modules\Notification\Application\Services\NotificationDeliveryService;
use App\Modules\Notification\Application\Services\NotificationPreferenceService;
use App\Modules\Notification\Domain\Contracts\DeviceRegistryInterface;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationDeliveryServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_marketing_opted_out_creates_no_delivery_rows_and_dispatches_no_jobs(): void
    {
        Queue::fake();

        $preferenceService = $this->createMock(NotificationPreferenceService::class);
        $preferenceService->method('getEnabledChannels')->willReturn([]);

        $deviceRegistry = $this->createMock(DeviceRegistryInterface::class);

        $service = new NotificationDeliveryService($preferenceService, $deviceRegistry);

        $notification = NotificationModel::create([
            'id'                => (string) Str::orderedUuid(),
            'source_event_id'   => (string) Str::uuid(),
            'source_event_type' => 'TestMarketingEvent',
            'notification_type' => 'marketing.promo',
            'category'          => NotificationCategory::Marketing->value,
            'mandatory'         => false,
            'recipient_id'      => (string) Str::uuid(),
            'subject'           => 'Special Offer',
            'body'              => 'Get 10% off today!',
            'status'            => NotificationStatus::Queued->value,
            'correlation_id'    => (string) Str::uuid(),
        ]);

        $service->dispatchToChannels($notification);

        $this->assertDatabaseMissing('notification.notification_deliveries', [
            'notification_id' => $notification->id,
        ]);

        Queue::assertNothingPushed();
    }

    public function test_in_app_channel_always_dispatches_delivery_job(): void
    {
        Queue::fake();

        $preferenceService = $this->createMock(NotificationPreferenceService::class);
        $preferenceService->method('getEnabledChannels')->willReturn([
            \App\Modules\Notification\Domain\Enums\NotificationChannel::InApp,
        ]);

        $deviceRegistry = $this->createMock(DeviceRegistryInterface::class);

        $service = new NotificationDeliveryService($preferenceService, $deviceRegistry);

        $notification = NotificationModel::create([
            'id'                => (string) Str::orderedUuid(),
            'source_event_id'   => (string) Str::uuid(),
            'source_event_type' => 'TestFinancialEvent',
            'notification_type' => 'payment.qr.received',
            'category'          => NotificationCategory::Financial->value,
            'mandatory'         => true,
            'recipient_id'      => (string) Str::uuid(),
            'subject'           => 'Payment received',
            'body'              => 'You received 100 BWP.',
            'status'            => NotificationStatus::Queued->value,
            'correlation_id'    => (string) Str::uuid(),
        ]);

        $service->dispatchToChannels($notification);

        $this->assertDatabaseHas('notification.notification_deliveries', [
            'notification_id' => $notification->id,
            'channel'         => 'in_app',
        ], 'notification');

        Queue::assertPushed(DeliverInAppNotificationJob::class);
    }
}
