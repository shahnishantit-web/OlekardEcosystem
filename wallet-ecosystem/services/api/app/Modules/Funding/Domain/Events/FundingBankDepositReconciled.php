<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class FundingBankDepositReconciled implements IntegrationEventInterface
{
    public function __construct(
        public string $fundingTransactionId,
        public string $walletId,
        public string $currencyCode,
        public int    $amount,
        public string $bankNotificationId,
        public string $bankReferenceCode,
        public string $status,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string { return 'Funding.BankDeposit.Reconciled'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'funding_transaction_id' => $this->fundingTransactionId,
            'wallet_id'              => $this->walletId,
            'currency_code'          => $this->currencyCode,
            'amount'                 => $this->amount,
            'bank_notification_id'   => $this->bankNotificationId,
            'bank_reference_code'    => $this->bankReferenceCode,
            'status'                 => $this->status,
            'occurred_at'            => $this->occurredAt,
            'correlation_id'         => $this->correlationId,
        ];
    }
}
