<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class FxQuoteIssuedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $customerId,
        public string $quoteId,
        public string $fromCurrencyCode,
        public string $toCurrencyCode,
        public string $fromAmount,
        public string $toAmount,
        public string $expiresAt,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'MultiCurrency.FxQuote.Issued'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'customer_id'        => $this->customerId,
            'quote_id'           => $this->quoteId,
            'from_currency_code' => $this->fromCurrencyCode,
            'to_currency_code'   => $this->toCurrencyCode,
            'from_amount'        => $this->fromAmount,
            'to_amount'          => $this->toAmount,
            'expires_at'         => $this->expiresAt,
            'occurred_at'        => $this->occurredAt,
            'correlation_id'     => $this->correlationId,
        ];
    }
}
