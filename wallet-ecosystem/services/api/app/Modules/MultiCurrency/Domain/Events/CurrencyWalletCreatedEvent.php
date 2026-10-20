<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class CurrencyWalletCreatedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $customerId,
        public string $currencyWalletId,
        public string $currencyCode,
        public string $walletId,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'MultiCurrency.CurrencyWallet.Created'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'customer_id'        => $this->customerId,
            'currency_wallet_id' => $this->currencyWalletId,
            'currency_code'      => $this->currencyCode,
            'wallet_id'          => $this->walletId,
            'occurred_at'        => $this->occurredAt,
            'correlation_id'     => $this->correlationId,
        ];
    }
}
