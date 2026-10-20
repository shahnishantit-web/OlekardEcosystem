<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

/**
 * Lightweight integration event contract shared by all domain modules.
 *
 * Module events implement this minimal interface directly (eventName, occurredAt,
 * correlationId, toArray). For the full outbox contract (eventId, aggregateId, etc.)
 * used by OutboxEventPublisher, extend BaseIntegrationEvent.
 */
interface IntegrationEventInterface
{
    public function eventName(): string;

    public function occurredAt(): string;

    public function correlationId(): string;

    public function toArray(): array;
}
