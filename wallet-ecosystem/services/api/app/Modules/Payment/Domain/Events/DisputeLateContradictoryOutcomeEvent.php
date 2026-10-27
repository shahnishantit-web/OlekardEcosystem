<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeLateContradictoryOutcomeEvent implements IntegrationEventInterface
{
    public function __construct(
        private string  $disputeCaseId,
        private string  $paymentAttemptId,
        private string  $currentStatus,
        private string  $contradictoryOutcome,
        private ?string $providerDecisionReference,
        private string  $correlationId,
        private string  $occurredAt,
        private int     $receivedAtEpochMs,
    ) {
    }

    public function eventName(): string { return 'payment.dispute_case.late_contradictory_outcome'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }
    public function aggregateType(): string { return 'payment_dispute_case'; }
    public function aggregateId(): string { return $this->disputeCaseId; }

    /** Idempotency key includes epoch ms to uniquely identify each contradictory event. */
    public function idempotencyKey(): string
    {
        return "dispute.contradictory:{$this->disputeCaseId}:{$this->receivedAtEpochMs}";
    }

    public function toArray(): array
    {
        return [
            'event'                       => $this->eventName(),
            'dispute_case_id'             => $this->disputeCaseId,
            'payment_attempt_id'          => $this->paymentAttemptId,
            'current_status'              => $this->currentStatus,
            'contradictory_outcome'       => $this->contradictoryOutcome,
            'provider_decision_reference' => $this->providerDecisionReference,
            'correlation_id'              => $this->correlationId,
            'occurred_at'                 => $this->occurredAt,
        ];
    }
}
