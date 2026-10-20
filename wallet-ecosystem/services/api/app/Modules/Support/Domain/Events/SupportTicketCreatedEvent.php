<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class SupportTicketCreatedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $ticketId,
        public string $ticketNumber,
        public string $reporterId,
        public string $reporterType,
        public string $category,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Ticket.Created'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'ticket_id'     => $this->ticketId,
            'ticket_number' => $this->ticketNumber,
            'reporter_id'   => $this->reporterId,
            'reporter_type' => $this->reporterType,
            'category'      => $this->category,
            'occurred_at'   => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
