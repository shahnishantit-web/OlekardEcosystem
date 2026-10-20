<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class FeePostedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $feeId,
        public string $transactionId,
        public int    $amountMinor,
        public string $currency,
        public string $ledgerEntryId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'Fees.Fee.Posted'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'fee_id'          => $this->feeId,
            'transaction_id'  => $this->transactionId,
            'amount_minor'    => $this->amountMinor,
            'currency'        => $this->currency,
            'ledger_entry_id' => $this->ledgerEntryId,
            'occurred_at'     => $this->occurredAt,
            'correlation_id'  => $this->correlationId,
        ];
    }
}
