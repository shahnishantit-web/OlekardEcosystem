<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Application\Jobs\DeliverEmailNotificationJob;
use App\Modules\Notification\Application\Jobs\DeliverInAppNotificationJob;
use App\Modules\Notification\Application\Jobs\DeliverPushNotificationJob;
use App\Modules\Notification\Application\Jobs\DeliverSmsNotificationJob;
use App\Modules\Notification\Domain\Contracts\DeviceRegistryInterface;
use App\Modules\Notification\Domain\Enums\DeliveryStatus;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationChannel;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class NotificationDeliveryService
{
    public function __construct(
        private readonly NotificationPreferenceService $preferenceService,
        private readonly DeviceRegistryInterface $deviceRegistry,
    ) {}

    public function dispatchToChannels(NotificationModel $notification): void
    {
        $notification->status = NotificationStatus::Dispatching;
        $notification->save();

        $enabledChannels = $this->preferenceService->getEnabledChannels(
            $notification->recipient_id,
            $notification->category,
            $notification->mandatory,
            $notification->id,
        );

        if (! $notification->mandatory && ! $notification->category->isMandatory() && $notification->rule_channels !== null) {
            $enabledChannels = array_values(array_filter($enabledChannels, fn (NotificationChannel $channel) => in_array($channel->value, $notification->rule_channels, true)));
        }

        if (empty($enabledChannels) && ! $notification->mandatory) {
            $isMarketing = $notification->category instanceof NotificationCategory
                ? $notification->category === NotificationCategory::Marketing
                : $notification->category === 'marketing';

            if ($isMarketing) {
                Log::info('notification.marketing_discarded', [
                    'recipient_id' => $notification->recipient_id,
                    'notification_type' => $notification->notification_type,
                    'correlation_id' => $notification->correlation_id,
                ]);
            } else {
                Log::warning('notification.no_channels_enabled', [
                    'recipient_id' => $notification->recipient_id,
                    'notification_type' => $notification->notification_type,
                    'correlation_id' => $notification->correlation_id,
                ]);

                $notification->status = NotificationStatus::Failed;
                $notification->save();
            }

            return;
        }

        foreach ($enabledChannels as $channel) {
            if ($channel === NotificationChannel::Push) {
                $tokens = $this->deviceRegistry->getActiveTokensForUser($notification->recipient_id);
                if (empty($tokens)) {
                    continue;
                }
            }

            $delivery = NotificationDeliveryModel::create([
                'id' => (string) Str::orderedUuid(),
                'notification_id' => $notification->id,
                'channel' => $channel->value,
                'status' => DeliveryStatus::Queued->value,
            ]);

            $this->dispatchChannelJob($channel, $delivery->id, $notification);
        }
    }

    private function dispatchChannelJob(NotificationChannel $channel, string $deliveryId, NotificationModel $notification): void
    {
        $queue = $notification->mandatory ? 'notifications-critical' : 'notifications-bulk';

        match ($channel) {
            NotificationChannel::Push => DeliverPushNotificationJob::dispatch($deliveryId)->onQueue($queue),
            NotificationChannel::Sms => DeliverSmsNotificationJob::dispatch($deliveryId)->onQueue($queue),
            NotificationChannel::Email => DeliverEmailNotificationJob::dispatch($deliveryId)->onQueue($queue),
            NotificationChannel::InApp => DeliverInAppNotificationJob::dispatch($deliveryId)->onQueue($queue),
        };
    }
}
