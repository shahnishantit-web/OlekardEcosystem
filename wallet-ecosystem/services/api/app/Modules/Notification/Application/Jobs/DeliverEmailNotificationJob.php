<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Jobs;

use App\Modules\Notification\Domain\Contracts\EmailChannelInterface;
use App\Modules\Notification\Domain\Contracts\UserContactServiceInterface;
use App\Modules\Notification\Domain\Enums\DeliveryStatus;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
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

final class DeliverEmailNotificationJob implements ShouldQueue
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

    public function handle(EmailChannelInterface $email, UserContactServiceInterface $contacts, NotificationMetrics $metrics, AuditServiceInterface $audit): void
    {
        $this->executeDelivery(function () use ($email, $contacts, $metrics, $audit): void {
            $delivery = NotificationDeliveryModel::findOrFail($this->deliveryId);
            $notification = $delivery->notification;

            if ($notification === null) {
                Log::warning('notification.delivery.notification_missing', ['delivery_id' => $this->deliveryId]);

                return;
            }

            $delivery->increment('attempt_count');
            $delivery->last_attempt_at = now();

            $emailAddress = $contacts->getEmailForUser($notification->recipient_id);

            if ($emailAddress === null) {
                $delivery->status = DeliveryStatus::Failed;
                $delivery->error_code = 'NO_EMAIL_ON_RECORD';
                $delivery->save();
                $metrics->notificationFailed('email', 'NO_EMAIL_ON_RECORD');

                Log::warning('notification.email.no_email', [
                    'recipient_id' => $notification->recipient_id,
                    'notification_id' => $notification->id,
                ]);

                return; // Non-retryable
            }

            $result = $email->send($emailAddress, $notification->subject, $notification->body);

            if ($result->success) {
                $delivery->status = DeliveryStatus::Sent;
                $delivery->provider_ref = $result->providerRef;
                $delivery->save();

                $category = $notification->category instanceof NotificationCategory ? $notification->category->value : (string) $notification->category;
                $metrics->notificationDelivered('email', $category);

                $audit->record(new AuditEventData(
                    action: 'notification.delivered',
                    actorType: 'system',
                    resourceType: 'notification_delivery',
                    correlationId: $notification->correlation_id ?? 'unknown',
                    outcome: 'success',
                    resourceId: $this->deliveryId,
                    metadata: ['channel' => 'email', 'category' => $category, 'provider_ref' => $result->providerRef],
                ));

                return;
            }

            $delivery->status = DeliveryStatus::Failed;
            $delivery->save();
            $metrics->notificationRetry('email');

            throw new \RuntimeException("Email delivery failed for delivery {$this->deliveryId}");
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
                metadata: ['channel' => 'email', 'error' => $e->getMessage()],
            ));

            app(NotificationMetrics::class)->notificationPermanentlyFailed('email');

            Log::error('notification.email.permanently_failed', [
                'delivery_id' => $this->deliveryId,
                'notification_id' => $notification?->id,
                'error' => $e->getMessage(),
            ]);
        });
    }
}
