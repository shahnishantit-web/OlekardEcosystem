<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class PaymentConfirmedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string $paymentIntentId,
        private string $paymentAttemptId,
        private string $purposeType,
        private string $purposeReference,
        private string $organisationId,
        private string $payerActorId,
        private int    $amountMinor,
        private string $currencyCode,
        private string $providerCode,
        private int    $feeMinor,
        private string $correlationId,
        private string $occurredAt,
    ) {
    }

    public function eventName(): string
    {
        return 'payment.confirmed';
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
            'event'              => $this->eventName(),
            'payment_intent_id'  => $this->paymentIntentId,
            'payment_attempt_id' => $this->paymentAttemptId,
            'purpose_type'       => $this->purposeType,
            'purpose_reference'  => $this->purposeReference,
            'organisation_id'    => $this->organisationId,
            'payer_actor_id'     => $this->payerActorId,
            'amount_minor'       => $this->amountMinor,
            'currency_code'      => $this->currencyCode,
            'provider_code'      => $this->providerCode,
            'fee_minor'          => $this->feeMinor,
            'correlation_id'     => $this->correlationId,
            'occurred_at'        => $this->occurredAt,
        ];
    }
}
