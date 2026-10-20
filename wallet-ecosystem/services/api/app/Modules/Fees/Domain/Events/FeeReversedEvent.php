<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class FeeReversedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $feeId,
        public string $transactionId,
        public string $reversalLedgerEntryId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Fees.Fee.Reversed'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'fee_id'                  => $this->feeId,
            'transaction_id'          => $this->transactionId,
            'reversal_ledger_entry_id' => $this->reversalLedgerEntryId,
            'occurred_at'             => $this->occurredAt,
            'correlation_id'          => $this->correlationId,
        ];
    }
}
