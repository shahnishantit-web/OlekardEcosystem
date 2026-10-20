<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeRaisedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string  $disputeId,
        public string  $disputeNumber,
        public string  $ticketId,
        public string  $transactionId,
        public string  $raisedById,
        public string  $occurredAt,
        public string  $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Dispute.Raised'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'dispute_id'     => $this->disputeId,
            'dispute_number' => $this->disputeNumber,
            'ticket_id'      => $this->ticketId,
            'transaction_id' => $this->transactionId,
            'raised_by_id'   => $this->raisedById,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
