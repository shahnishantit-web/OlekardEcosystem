<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain\Events;

use App\Modules\Platform\Domain\Events\BaseIntegrationEvent;

final class EventCancellationEffective extends BaseIntegrationEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        string $eventId,
        string $actorId,
        string $correlationId,
        private readonly array $payload,
    ) {
        parent::__construct($eventId, $actorId, $correlationId);
    }

    public function eventName(): string
    {
        return 'Events.Cancellation.Effective';
    }

    public function aggregateType(): string
    {
        return 'event';
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
