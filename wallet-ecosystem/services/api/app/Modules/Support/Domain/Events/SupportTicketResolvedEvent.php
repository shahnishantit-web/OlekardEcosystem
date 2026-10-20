<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class SupportTicketResolvedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $ticketId,
        public string $actorId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Ticket.Resolved'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'ticket_id'    => $this->ticketId,
            'actor_id'     => $this->actorId,
            'occurred_at'  => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
