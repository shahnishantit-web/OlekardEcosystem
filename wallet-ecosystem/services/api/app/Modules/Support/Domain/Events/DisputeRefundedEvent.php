<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeRefundedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $disputeId,
        public string $refundTransactionId,
        public string $confirmedById,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Support.Dispute.Refunded'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'dispute_id'           => $this->disputeId,
            'refund_transaction_id' => $this->refundTransactionId,
            'confirmed_by_id'      => $this->confirmedById,
            'occurred_at'          => $this->occurredAt,
            'correlation_id'       => $this->correlationId,
        ];
    }
}
