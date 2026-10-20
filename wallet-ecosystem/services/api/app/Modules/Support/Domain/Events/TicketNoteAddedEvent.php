<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class TicketNoteAddedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $ticketId,
        public string $noteId,
        public string $authorId,
        public bool   $isInternal,
        public string $reporterId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Ticket.NoteAdded'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'ticket_id'    => $this->ticketId,
            'note_id'      => $this->noteId,
            'author_id'    => $this->authorId,
            'is_internal'  => $this->isInternal,
            'reporter_id'  => $this->reporterId,
            'occurred_at'  => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
