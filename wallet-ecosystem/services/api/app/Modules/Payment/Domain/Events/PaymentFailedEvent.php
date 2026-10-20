<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class PaymentFailedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string $paymentIntentId,
        private string $purposeType,
        private string $purposeReference,
        private string $organisationId,
        private string $payerActorId,
        private string $failureReason,
        private string $correlationId,
        private string $occurredAt,
    ) {
    }

    public function eventName(): string
    {
        return 'payment.failed';
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
            'event'             => $this->eventName(),
            'payment_intent_id' => $this->paymentIntentId,
            'purpose_type'      => $this->purposeType,
            'purpose_reference' => $this->purposeReference,
            'organisation_id'   => $this->organisationId,
            'payer_actor_id'    => $this->payerActorId,
            'failure_reason'    => $this->failureReason,
            'correlation_id'    => $this->correlationId,
            'occurred_at'       => $this->occurredAt,
        ];
    }
}
