<?php

declare(strict_types=1);

// STUB — blocked pending AD-10 and LC14 resolution.
// Replace this stub with the full implementation once settlement confirmation is unblocked.

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Integration\Domain\Contracts\IntegrationEventInterface;
use DateTimeImmutable;

final class PayoutSettledEvent implements IntegrationEventInterface
{
    public function __construct(
        private readonly string $payoutRequestId,
        private readonly string $settlementRecordId,
        private readonly int $settledAmountMinor,
        private readonly string $currencyCode,
        private readonly string $correlationId,
        private readonly DateTimeImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'payment.payout.settled';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function toArray(): array
    {
        return [
            'payout_request_id'    => $this->payoutRequestId,
            'settlement_record_id' => $this->settlementRecordId,
            'settled_amount_minor' => $this->settledAmountMinor,
            'currency_code'        => $this->currencyCode,
        ];
    }
}
