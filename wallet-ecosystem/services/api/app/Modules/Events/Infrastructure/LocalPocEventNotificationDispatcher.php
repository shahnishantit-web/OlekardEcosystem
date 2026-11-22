<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventCancellationNotificationDispatcher;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Captures simulated notices; it never invokes email, SMS, push or an external provider. */
final class LocalPocEventNotificationDispatcher implements EventCancellationNotificationDispatcher
{
    public function dispatch(CreateNotificationCommand $command): void
    {
        abort_unless(app()->environment('local') && config('events.poc_enabled') === true
            && config('events.poc_demo_notifications_enabled') === true, 503, 'Local POC notifications are disabled.');
        DB::connection('community_services')->table('community_services.event_poc_notifications')->insertOrIgnore([
            'id' => (string) Uuid::uuid7(), 'source_event_id' => $command->sourceEventId,
            'source_event_type' => $command->sourceEventType, 'notification_type' => $command->notificationType,
            'recipient_actor_id' => $command->recipientId,
            'variables' => json_encode($command->variables, JSON_THROW_ON_ERROR),
            'status' => 'simulated_delivered', 'simulated_at' => now(), 'created_at' => now(),
        ]);
    }
}
