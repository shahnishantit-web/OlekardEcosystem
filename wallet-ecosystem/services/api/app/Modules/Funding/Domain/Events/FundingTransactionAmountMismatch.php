<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class FundingTransactionAmountMismatch implements IntegrationEventInterface
{
    public function __construct(
        public string $fundingTransactionId,
        public string $walletId,
        public string $currencyCode,
        public int    $expectedAmount,
        public int    $receivedAmount,
        public string $status,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string { return 'Funding.Transaction.AmountMismatch'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'funding_transaction_id' => $this->fundingTransactionId,
            'wallet_id'              => $this->walletId,
            'currency_code'          => $this->currencyCode,
            'expected_amount'        => $this->expectedAmount,
            'received_amount'        => $this->receivedAmount,
            'status'                 => $this->status,
            'occurred_at'            => $this->occurredAt,
            'correlation_id'         => $this->correlationId,
        ];
    }
}
