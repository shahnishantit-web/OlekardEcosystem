<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Services;

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;

class NotificationMetrics
{
    public function __construct(private readonly MetricsCollectorInterface $collector) {}

    public function notificationCreated(string $category): void
    {
        $this->collector->increment('notification.created', ['category' => $category]);
    }

    public function notificationDelivered(string $channel, string $category): void
    {
        $this->collector->increment('notification.delivered', [
            'channel'  => $channel,
            'category' => $category,
        ]);
    }

    public function notificationPermanentlyFailed(string $channel): void
    {
        $this->collector->increment('notification.permanently_failed', ['channel' => $channel]);
    }

    public function notificationRetry(string $channel): void
    {
        $this->collector->increment('notification.retry', ['channel' => $channel]);
    }

    public function notificationFailed(string $channel, string $errorCode): void
    {
        $this->collector->increment('notification.failed', [
            'channel'    => $channel,
            'error_code' => $errorCode,
        ]);
    }
}
