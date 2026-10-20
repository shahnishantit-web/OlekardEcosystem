<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class FxConversionFailedEvent implements IntegrationEventInterface
{
    public function __construct(
        public string $customerId,
        public string $quoteId,
        public string $reason,
        public string $occurredAt,
        public string $correlationId,
    ) {}

    public function eventName(): string     { return 'MultiCurrency.FxConversion.Failed'; }
    public function occurredAt(): string    { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }

    public function toArray(): array
    {
        return [
            'customer_id'    => $this->customerId,
            'quote_id'       => $this->quoteId,
            'reason'         => $this->reason,
            'occurred_at'    => $this->occurredAt,
            'correlation_id' => $this->correlationId,
        ];
    }
}
