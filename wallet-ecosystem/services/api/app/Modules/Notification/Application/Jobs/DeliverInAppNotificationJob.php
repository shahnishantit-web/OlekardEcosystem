<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Jobs;

use App\Modules\Notification\Domain\Enums\DeliveryStatus;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Domain\Services\NotificationMetrics;
use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class DeliverInAppNotificationJob implements ShouldQueue
{
    use DeliveryExecutionGuard, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $deliveryId,
    ) {}

    public function handle(NotificationMetrics $metrics, AuditServiceInterface $audit): void
    {
        $this->executeDelivery(function () use ($metrics, $audit): void {
            $delivery = NotificationDeliveryModel::findOrFail($this->deliveryId);
            $notification = $delivery->notification;

            if ($notification === null) {
                Log::warning('notification.delivery.notification_missing', ['delivery_id' => $this->deliveryId]);

                return;
            }

            $delivery->status = DeliveryStatus::Delivered;
            $delivery->last_attempt_at = now();
            $delivery->save();

            $category = $notification->category instanceof NotificationCategory
                ? $notification->category->value
                : (string) $notification->category;

            $metrics->notificationDelivered('in_app', $category);

            $audit->record(new AuditEventData(
                action: 'notification.delivered',
                actorType: 'system',
                resourceType: 'notification_delivery',
                correlationId: $notification->correlation_id ?? 'unknown',
                outcome: 'success',
                resourceId: $this->deliveryId,
                metadata: ['channel' => 'in_app', 'category' => $category],
            ));

            $this->updateNotificationStatus($delivery);
        });
    }

    private function updateNotificationStatus(NotificationDeliveryModel $delivery): void
    {
        $notification = $delivery->notification;
        if ($notification === null) {
            return;
        }

        $deliveries = $notification->deliveries;
        $statuses = $deliveries->pluck('status')->map(fn ($s) => $s instanceof DeliveryStatus ? $s->value : $s)->all();

        $allDone = count(array_filter($statuses, fn ($s) => $s === DeliveryStatus::Delivered->value)) === count($statuses);
        $anyDelivered = in_array(DeliveryStatus::Delivered->value, $statuses, true);
        $anyFailed = in_array(DeliveryStatus::PermanentlyFailed->value, $statuses, true);

        if ($allDone) {
            $notification->status = NotificationStatus::Delivered;
        } elseif ($anyDelivered && $anyFailed) {
            $notification->status = NotificationStatus::PartiallyDelivered;
        } elseif ($anyFailed) {
            $notification->status = NotificationStatus::Failed;
        }

        $notification->save();
    }
}
