<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class BankDepositReconciled implements IntegrationEventInterface
{
    private string $occurred;
    public function __construct(public string $fundingTransactionId,
        public string $bankReferenceCode, public int $amount, public string $correlationId)
    {
        $this->occurred = now()->toIso8601String();
    }
    public function eventName(): string { return 'Funding.BankDeposit.Reconciled'; }
    public function occurredAt(): string { return $this->occurred; }
    public function correlationId(): string { return $this->correlationId; }
    public function toArray(): array
    {
        return ['funding_transaction_id' => $this->fundingTransactionId,
            'bank_reference_code' => $this->bankReferenceCode, 'amount' => $this->amount,
            'correlation_id' => $this->correlationId, 'occurred_at' => $this->occurred];
    }
}
