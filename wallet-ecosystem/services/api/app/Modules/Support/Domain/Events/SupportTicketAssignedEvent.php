<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class SupportTicketAssignedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $ticketId,
        public string $assigneeId,
        public string $assignedById,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Ticket.Assigned'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'ticket_id'    => $this->ticketId,
            'assignee_id'  => $this->assigneeId,
            'assigned_by'  => $this->assignedById,
            'occurred_at'  => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
