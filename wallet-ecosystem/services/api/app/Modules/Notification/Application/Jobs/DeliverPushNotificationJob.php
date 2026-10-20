<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Jobs;

use App\Modules\Notification\Domain\Contracts\DeviceRegistryInterface;
use App\Modules\Notification\Domain\Contracts\PushChannelInterface;
use App\Modules\Notification\Domain\Enums\DeliveryStatus;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Domain\Services\NotificationMetrics;
use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class DeliverPushNotificationJob implements ShouldQueue
{
    use DeliveryExecutionGuard, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(
        private readonly string $deliveryId,
    ) {
        $this->tries = (int) config('notifications.retry.max_attempts', 5);
    }

    public function backoff(): array
    {
        $base = (int) config('notifications.retry.initial_delay_seconds', 30);

        return [$base, $base * 2, $base * 4, $base * 8, $base * 16];
    }

    public function handle(PushChannelInterface $push, DeviceRegistryInterface $registry, NotificationMetrics $metrics, AuditServiceInterface $audit): void
    {
        $this->executeDelivery(function () use ($push, $registry, $metrics, $audit): void {
            $delivery = NotificationDeliveryModel::findOrFail($this->deliveryId);
            $notification = $delivery->notification;

            if ($notification === null) {
                Log::warning('notification.delivery.notification_missing', ['delivery_id' => $this->deliveryId]);

                return;
            }

            $delivery->increment('attempt_count');
            $delivery->last_attempt_at = now();
            $delivery->save();

            $tokens = $registry->getActiveTokensForUser($notification->recipient_id);

            foreach ($tokens as $token) {
                $result = $push->send(
                    $token->tokenValue,
                    $token->platform,
                    $notification->subject,
                    $notification->body,
                );

                if ($result->tokenInvalid) {
                    $registry->invalidateToken($token->id);
                    $delivery->status = DeliveryStatus::Failed;
                    $delivery->error_code = 'TOKEN_INVALID';
                    $delivery->save();
                    $metrics->notificationFailed('push', 'TOKEN_INVALID');

                    return; // No retry for invalid tokens
                }

                if ($result->success) {
                    $delivery->status = DeliveryStatus::Sent;
                    $delivery->provider_ref = $result->providerRef;
                    $delivery->save();

                    $category = $notification->category instanceof NotificationCategory ? $notification->category->value : (string) $notification->category;
                    $metrics->notificationDelivered('push', $category);

                    $audit->record(new AuditEventData(
                        action: 'notification.delivered',
                        actorType: 'system',
                        resourceType: 'notification_delivery',
                        correlationId: $notification->correlation_id ?? 'unknown',
                        outcome: 'success',
                        resourceId: $this->deliveryId,
                        metadata: ['channel' => 'push', 'category' => $category, 'provider_ref' => $result->providerRef],
                    ));

                    return;
                }
            }

            $delivery->status = DeliveryStatus::Failed;
            $delivery->save();
            $metrics->notificationRetry('push');

            throw new \RuntimeException("Push delivery failed for delivery {$this->deliveryId}");
        });
    }

    public function failed(\Throwable $e): void
    {
        $this->executeDelivery(function () use ($e): void {
            $delivery = NotificationDeliveryModel::find($this->deliveryId);

            if ($delivery === null) {
                return;
            }

            $delivery->status = DeliveryStatus::PermanentlyFailed;
            $delivery->save();

            $notification = $delivery->notification;
            $correlationId = $notification?->correlation_id ?? 'unknown';

            app(AuditServiceInterface::class)->record(new AuditEventData(
                action: 'notification.permanently_failed',
                actorType: 'system',
                resourceType: 'notification_delivery',
                correlationId: $correlationId,
                outcome: 'failure',
                resourceId: $this->deliveryId,
                metadata: ['channel' => 'push', 'error' => $e->getMessage()],
            ));

            app(NotificationMetrics::class)->notificationPermanentlyFailed('push');

            Log::error('notification.push.permanently_failed', [
                'delivery_id' => $this->deliveryId,
                'notification_id' => $notification?->id,
                'error' => $e->getMessage(),
            ]);

            if ($notification !== null) {
                $this->updateNotificationStatus($notification);
            }
        });
    }

    private function updateNotificationStatus(NotificationModel $notification): void
    {
        $statuses = $notification->deliveries->pluck('status')->map(fn ($s) => $s instanceof DeliveryStatus ? $s->value : $s)->all();

        $anyDelivered = in_array(DeliveryStatus::Delivered->value, $statuses, true);
        $anyFailed = in_array(DeliveryStatus::PermanentlyFailed->value, $statuses, true);
        $anyPending = in_array(DeliveryStatus::Queued->value, $statuses, true) || in_array(DeliveryStatus::Sent->value, $statuses, true);

        if (! $anyPending) {
            $notification->status = $anyDelivered && $anyFailed
                ? NotificationStatus::PartiallyDelivered
                : ($anyFailed ? NotificationStatus::Failed : NotificationStatus::Delivered);
            $notification->save();
        }
    }
}
