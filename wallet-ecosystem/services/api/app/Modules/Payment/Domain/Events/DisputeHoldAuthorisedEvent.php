<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeHoldAuthorisedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string  $disputeCaseId,
        private string  $paymentAttemptId,
        private ?string $organisationId,
        private int     $holdAmountMinor,
        private string  $currencyCode,
        private string  $exposureType,
        private string  $authorisedBy,
        private string  $correlationId,
        private string  $occurredAt,
    ) {
    }

    public function eventName(): string { return 'payment.dispute_case.hold_authorised'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }
    public function aggregateType(): string { return 'payment_dispute_case'; }
    public function aggregateId(): string { return $this->disputeCaseId; }

    public function toArray(): array
    {
        return [
            'event'              => $this->eventName(),
            'dispute_case_id'    => $this->disputeCaseId,
            'payment_attempt_id' => $this->paymentAttemptId,
            'organisation_id'    => $this->organisationId,
            'hold_amount_minor'  => $this->holdAmountMinor,
            'currency_code'      => $this->currencyCode,
            'exposure_type'      => $this->exposureType,
            'authorised_by'      => $this->authorisedBy,
            'correlation_id'     => $this->correlationId,
            'occurred_at'        => $this->occurredAt,
        ];
    }
}
