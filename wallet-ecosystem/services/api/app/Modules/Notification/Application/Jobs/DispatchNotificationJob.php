<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Jobs;

use App\Modules\Notification\Application\Services\NotificationDeliveryService;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class DispatchNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $notificationId,
    ) {}

    public function handle(NotificationDeliveryService $deliveryService): void
    {
        try {
            $notification = NotificationModel::findOrFail($this->notificationId);
            $deliveryService->dispatchToChannels($notification);
        } catch (\Throwable $e) {
            Log::error('notification.dispatch.failed', [
                'notification_id' => $this->notificationId,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
