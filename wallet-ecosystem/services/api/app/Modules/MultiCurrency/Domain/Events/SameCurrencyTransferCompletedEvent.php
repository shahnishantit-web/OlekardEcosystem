<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class SameCurrencyTransferCompletedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $customerId,
        public string $transferId,
        public string $fromWalletId,
        public string $toWalletId,
        public string $currencyCode,
        public string $amount,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'MultiCurrency.SameCurrencyTransfer.Completed'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'customer_id'     => $this->customerId,
            'transfer_id'     => $this->transferId,
            'from_wallet_id'  => $this->fromWalletId,
            'to_wallet_id'    => $this->toWalletId,
            'currency_code'   => $this->currencyCode,
            'amount'          => $this->amount,
            'occurred_at'     => $this->occurredAt,
            'correlation_id'  => $this->correlationId,
        ];
    }
}
