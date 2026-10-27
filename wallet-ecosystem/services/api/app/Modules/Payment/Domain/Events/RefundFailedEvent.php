<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class RefundFailedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string $refundId,
        private string $paymentIntentId,
        private string $paymentAttemptId,
        private string $organisationId,
        private string $actorId,
        private string $failureReason,
        private string $correlationId,
        private string $occurredAt,
    ) {
    }

    public function eventName(): string
    {
        return 'payment.refund.failed';
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
        return 'payment_refund';
    }

    public function aggregateId(): string
    {
        return $this->refundId;
    }

    public function toArray(): array
    {
        return [
            'event'              => $this->eventName(),
            'refund_id'          => $this->refundId,
            'payment_intent_id'  => $this->paymentIntentId,
            'payment_attempt_id' => $this->paymentAttemptId,
            'organisation_id'    => $this->organisationId,
            'actor_id'           => $this->actorId,
            'failure_reason'     => $this->failureReason,
            'correlation_id'     => $this->correlationId,
            'occurred_at'        => $this->occurredAt,
        ];
    }
}
