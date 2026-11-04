<?php

declare(strict_types=1);

namespace App\Modules\Events\Application;

use App\Modules\Events\Domain\Contracts\EventCancellationNotificationDispatcher;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Resolves purchasers at delivery time; missing approved template/rules fail the shared outbox delivery visibly. */
final class EventCancellationNotificationListener
{
    public function __construct(private readonly EventCancellationNotificationDispatcher $notifications) {}

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        $eventId = (string) ($payload['event_id'] ?? '');
        $requestId = (string) ($payload['cancellation_request_id'] ?? '');
        $correlationId = (string) ($payload['correlation_id'] ?? $payload['eventId'] ?? $requestId);
        if (! Uuid::isValid($eventId) || ! Uuid::isValid($requestId)) {
            throw new \UnexpectedValueException('Cancellation notification event is missing authoritative identifiers.');
        }

        $db = DB::connection('community_services');
        $event = $db->table('community_services.event_drafts')->where('id', $eventId)
            ->where('lifecycle_state', 'cancelled')->first(['title', 'starts_at']);
        if ($event === null) {
            throw new \UnexpectedValueException('Effective event cancellation evidence is unavailable.');
        }

        $recipients = $db->table('community_services.event_tickets')->where('event_id', $eventId)
            ->where('cancellation_request_id', $requestId)->distinct()->orderBy('owner_actor_id')->pluck('owner_actor_id');
        foreach ($recipients as $recipientId) {
            $sourceId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'event-cancellation:'.$requestId.':'.$recipientId)->toString();
            $this->notifications->dispatch(new CreateNotificationCommand(
                sourceEventId: $sourceId,
                sourceEventType: 'Events.Cancellation.Effective',
                notificationType: 'events.event_cancelled',
                category: NotificationCategory::Operational,
                mandatory: true,
                recipientId: (string) $recipientId,
                variables: ['event_title' => (string) $event->title, 'event_starts_at' => (string) $event->starts_at],
                correlationId: $correlationId,
            ));
        }
    }
}
