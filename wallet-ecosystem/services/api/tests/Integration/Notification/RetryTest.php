<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Modules\Notification\Application\Jobs\DeliverPushNotificationJob;
use App\Modules\Notification\Domain\Contracts\DeviceRegistryInterface;
use App\Modules\Notification\Domain\Contracts\PushChannelInterface;
use App\Modules\Notification\Domain\DTO\DeviceTokenRecord;
use App\Modules\Notification\Domain\DTO\PushDeliveryResult;
use App\Modules\Notification\Domain\Enums\DeliveryStatus;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Domain\Services\NotificationMetrics;
use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FailOnceLogPushChannel — fails on first call, succeeds on second.
 */
final class FailOnceLogPushChannel implements PushChannelInterface
{
    private int $callCount = 0;

    public function send(string $tokenValue, string $platform, string $subject, string $body, array $metadata = []): PushDeliveryResult
    {
        $this->callCount++;

        if ($this->callCount === 1) {
            throw new \RuntimeException('Simulated first-attempt failure');
        }

        return new PushDeliveryResult(true, 'ref-retry-ok', null, false);
    }
}

final class RetryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_push_delivery_succeeds_on_second_attempt_after_failure(): void
    {
        $channel = new FailOnceLogPushChannel();
        app()->instance(PushChannelInterface::class, $channel);

        $registry = $this->createMock(DeviceRegistryInterface::class);
        $registry->method('getActiveTokensForUser')->willReturn([
            new DeviceTokenRecord('tok-1', 'fcm-abc', 'android'),
        ]);
        app()->instance(DeviceRegistryInterface::class, $registry);

        $metrics = new NotificationMetrics($this->createMock(MetricsCollectorInterface::class));
        $audit   = $this->createMock(AuditServiceInterface::class);
        app()->instance(NotificationMetrics::class, $metrics);
        app()->instance(AuditServiceInterface::class, $audit);

        $notification = NotificationModel::create([
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

        $delivery = NotificationDeliveryModel::create([
            'id'              => (string) Str::orderedUuid(),
            'notification_id' => $notification->id,
            'channel'         => 'push',
            'status'          => DeliveryStatus::Queued->value,
            'attempt_count'   => 0,
        ]);

        $job = new DeliverPushNotificationJob($delivery->id);

        // First attempt — throws, increments attempt_count
        try {
            app()->call([$job, 'handle']);
        } catch (\RuntimeException) {
            // Expected failure
        }

        // Second attempt — succeeds
        app()->call([$job, 'handle']);

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        $this->assertSame(2, $delivery->attempt_count);
    }
}
