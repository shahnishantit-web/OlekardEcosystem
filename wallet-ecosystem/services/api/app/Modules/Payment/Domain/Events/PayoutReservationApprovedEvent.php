<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class PayoutReservationApprovedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string $payoutRequestId,
        private string $reservationId,
        private string $approvedBy,
        private int    $reservedAmountMinor,
        private string $currencyCode,
        private string $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'payment.payout.reservation_approved';
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }

    public function correlationId(): string
    {
        return $this->payoutRequestId;
    }

    public function idempotencyKey(): string
    {
        return "payout-reservation-approved:{$this->reservationId}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event'                  => $this->eventName(),
            'payout_request_id'      => $this->payoutRequestId,
            'reservation_id'         => $this->reservationId,
            'approved_by'            => $this->approvedBy,
            'reserved_amount_minor'  => $this->reservedAmountMinor,
            'currency_code'          => $this->currencyCode,
            'occurred_at'            => $this->occurredAt,
        ];
    }
}
