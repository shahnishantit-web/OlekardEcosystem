<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Application;

use App\Modules\Notification\Application\Jobs\DeliverPushNotificationJob;
use App\Modules\Notification\Domain\Contracts\DeviceRegistryInterface;
use App\Modules\Notification\Domain\Contracts\PushChannelInterface;
use App\Modules\Notification\Domain\DTO\DeviceTokenRecord;
use App\Modules\Notification\Domain\DTO\PushDeliveryResult;
use App\Modules\Notification\Domain\Enums\DeliveryStatus;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use App\Modules\Notification\Domain\Services\NotificationMetrics;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeliverPushNotificationJobTest extends TestCase
{
    use DatabaseTransactions;

    private function createNotification(): NotificationModel
    {
        return NotificationModel::create([
            'id'                => (string) Str::orderedUuid(),
            'source_event_id'   => (string) Str::uuid(),
            'source_event_type' => 'QrPaymentCompletedEvent',
            'notification_type' => 'payment.qr.received',
            'category'          => NotificationCategory::Financial->value,
            'mandatory'         => true,
            'recipient_id'      => (string) Str::uuid(),
            'subject'           => 'Payment received',
            'body'              => 'You received 100 BWP.',
            'status'            => NotificationStatus::Dispatching->value,
            'correlation_id'    => (string) Str::uuid(),
        ]);
    }

    private function createDelivery(string $notificationId): NotificationDeliveryModel
    {
        return NotificationDeliveryModel::create([
            'id'              => (string) Str::orderedUuid(),
            'notification_id' => $notificationId,
            'channel'         => 'push',
            'status'          => DeliveryStatus::Queued->value,
            'attempt_count'   => 0,
        ]);
    }

    public function test_successful_push_sets_status_to_sent(): void
    {
        $notification = $this->createNotification();
        $delivery     = $this->createDelivery($notification->id);

        $push = $this->createMock(PushChannelInterface::class);
        $push->method('send')->willReturn(new PushDeliveryResult(true, 'ref-123', null, false));

        $registry = $this->createMock(DeviceRegistryInterface::class);
        $registry->method('getActiveTokensForUser')->willReturn([
            new DeviceTokenRecord('tok-1', 'fcm-abc', 'android'),
        ]);

        $metrics = new NotificationMetrics($this->createMock(MetricsCollectorInterface::class));
        $audit   = $this->createMock(AuditServiceInterface::class);

        app()->instance(PushChannelInterface::class, $push);
        app()->instance(DeviceRegistryInterface::class, $registry);
        app()->instance(NotificationMetrics::class, $metrics);
        app()->instance(AuditServiceInterface::class, $audit);

        $job = new DeliverPushNotificationJob($delivery->id);
        app()->call([$job, 'handle']);

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        $this->assertSame('ref-123', $delivery->provider_ref);
    }

    public function test_permanently_failed_is_set_after_all_retries_exhausted(): void
    {
        $notification = $this->createNotification();
        $delivery     = $this->createDelivery($notification->id);

        $auditService = $this->createMock(AuditServiceInterface::class);
        $auditService->expects($this->once())
            ->method('record')
            ->with($this->callback(fn (AuditEventData $d) => $d->action === 'notification.permanently_failed'));

        app()->instance(AuditServiceInterface::class, $auditService);

        $job = new DeliverPushNotificationJob($delivery->id);
        $job->failed(new \RuntimeException('Push provider timeout'));

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::PermanentlyFailed, $delivery->status);
    }

    public function test_token_invalid_sets_failed_without_throwing(): void
    {
        $notification = $this->createNotification();
        $delivery     = $this->createDelivery($notification->id);

        $push = $this->createMock(PushChannelInterface::class);
        $push->method('send')->willReturn(new PushDeliveryResult(false, null, 'TOKEN_INVALID', true));

        $registry = $this->createMock(DeviceRegistryInterface::class);
        $registry->method('getActiveTokensForUser')->willReturn([
            new DeviceTokenRecord('tok-1', 'fcm-invalid', 'android'),
        ]);
        $registry->expects($this->once())->method('invalidateToken')->with('tok-1');

        $metrics = new NotificationMetrics($this->createMock(MetricsCollectorInterface::class));
        $audit   = $this->createMock(AuditServiceInterface::class);

        app()->instance(PushChannelInterface::class, $push);
        app()->instance(DeviceRegistryInterface::class, $registry);
        app()->instance(NotificationMetrics::class, $metrics);
        app()->instance(AuditServiceInterface::class, $audit);

        $job = new DeliverPushNotificationJob($delivery->id);

        // Should NOT throw
        app()->call([$job, 'handle']);

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
    }
}
