<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class FxConversionCompletedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $customerId,
        public string $fxTransactionId,
        public string $quoteId,
        public string $fromCurrencyCode,
        public string $toCurrencyCode,
        public string $fromAmount,
        public string $toAmount,
        public string $feeAmount,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'MultiCurrency.FxConversion.Completed'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'customer_id'        => $this->customerId,
            'fx_transaction_id'  => $this->fxTransactionId,
            'quote_id'           => $this->quoteId,
            'from_currency_code' => $this->fromCurrencyCode,
            'to_currency_code'   => $this->toCurrencyCode,
            'from_amount'        => $this->fromAmount,
            'to_amount'          => $this->toAmount,
            'fee_amount'         => $this->feeAmount,
            'occurred_at'        => $this->occurredAt,
            'correlation_id'     => $this->correlationId,
        ];
    }
}
