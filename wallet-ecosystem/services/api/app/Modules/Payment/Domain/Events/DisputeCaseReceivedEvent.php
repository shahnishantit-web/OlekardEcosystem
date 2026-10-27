<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeCaseReceivedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string  $disputeCaseId,
        private string  $paymentAttemptId,
        private string  $paymentIntentId,
        private ?string $organisationId,
        private string  $providerCode,
        private string  $providerDisputeReference,
        private string  $caseType,
        private int     $disputedAmountMinor,
        private string  $currencyCode,
        private ?string $reasonCode,
        private ?string $responseDeadline,
        private string  $correlationId,
        private string  $occurredAt,
    ) {
    }

    public function eventName(): string { return 'payment.dispute_case.received'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }
    public function aggregateType(): string { return 'payment_dispute_case'; }
    public function aggregateId(): string { return $this->disputeCaseId; }

    public function toArray(): array
    {
        return [
            'event'                      => $this->eventName(),
            'dispute_case_id'            => $this->disputeCaseId,
            'payment_attempt_id'         => $this->paymentAttemptId,
            'payment_intent_id'          => $this->paymentIntentId,
            'organisation_id'            => $this->organisationId,
            'provider_code'              => $this->providerCode,
            'provider_dispute_reference' => $this->providerDisputeReference,
            'case_type'                  => $this->caseType,
            'disputed_amount_minor'      => $this->disputedAmountMinor,
            'currency_code'              => $this->currencyCode,
            'reason_code'                => $this->reasonCode,
            'response_deadline'          => $this->responseDeadline,
            'correlation_id'             => $this->correlationId,
            'occurred_at'                => $this->occurredAt,
        ];
    }
}
