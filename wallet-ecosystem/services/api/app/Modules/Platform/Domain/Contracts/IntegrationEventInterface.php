<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

/**
 * Lightweight integration event contract shared by all domain modules.
 *
 * Module events implement this minimal interface directly (eventName, occurredAt,
 * correlationId, toArray). For the full outbox contract (eventId, aggregateId, etc.)
 * used by OutboxEventPublisher, extend BaseIntegrationEvent.
 *
 * @convention Events that carry causation context MAY additionally implement
 *   `causationId(): ?string` returning the prior outbox event's ID (null for root events).
 *   OutboxEventPublisher reads this via method_exists() — do NOT declare it on this interface,
 *   as adding a method declaration would require all 20+ existing implementors to be updated.
 *   (See research.md Decision 3 in the 060-outbox-inbox-delivery spec package.)
 */
interface IntegrationEventInterface
{
    public function eventName(): string;

    public function occurredAt(): string;

    public function correlationId(): string;

    public function toArray(): array;
}
