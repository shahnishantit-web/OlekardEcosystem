<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class RefundConfirmedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string $paymentIntentId,
        private string $paymentRefundId,
        private string $purposeType,
        private string $purposeReference,
        private string $organisationId,
        private int    $refundAmountMinor,
        private string $currencyCode,
        private bool   $isFullRefund,
        private int    $remainingRefundableMinor,
        private string $correlationId,
        private string $occurredAt,
    ) {
    }

    public function eventName(): string
    {
        return 'refund.confirmed';
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function aggregateType(): string
    {
        return 'payment_intent';
    }

    public function aggregateId(): string
    {
        return $this->paymentIntentId;
    }

    public function toArray(): array
    {
        return [
            'event'                      => $this->eventName(),
            'payment_intent_id'          => $this->paymentIntentId,
            'payment_refund_id'          => $this->paymentRefundId,
            'purpose_type'               => $this->purposeType,
            'purpose_reference'          => $this->purposeReference,
            'organisation_id'            => $this->organisationId,
            'refund_amount_minor'        => $this->refundAmountMinor,
            'currency_code'              => $this->currencyCode,
            'is_full_refund'             => $this->isFullRefund,
            'remaining_refundable_minor' => $this->remainingRefundableMinor,
            'correlation_id'             => $this->correlationId,
            'occurred_at'                => $this->occurredAt,
        ];
    }
}
