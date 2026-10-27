<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class DisputeLostEvent implements IntegrationEventInterface
{
    public function __construct(
        private string  $disputeCaseId,
        private string  $paymentAttemptId,
        private ?string $paymentIntentId,
        private ?string $organisationId,
        private int     $disputedAmountMinor,
        private int     $priorRefundsMinor,
        private int     $netChargebackMinor,
        private int     $chargebackFeeMinor,
        private string  $currencyCode,
        private ?string $chargebackLedgerJournalId,
        private string  $providerDecisionReference,
        private string  $correlationId,
        private string  $occurredAt,
    ) {
    }

    public function eventName(): string { return 'payment.dispute_case.lost'; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function correlationId(): string { return $this->correlationId; }
    public function aggregateType(): string { return 'payment_dispute_case'; }
    public function aggregateId(): string { return $this->disputeCaseId; }

    public function toArray(): array
    {
        return [
            'event'                          => $this->eventName(),
            'dispute_case_id'                => $this->disputeCaseId,
            'payment_attempt_id'             => $this->paymentAttemptId,
            'payment_intent_id'              => $this->paymentIntentId,
            'organisation_id'                => $this->organisationId,
            'disputed_amount_minor'          => $this->disputedAmountMinor,
            'prior_refunds_minor'            => $this->priorRefundsMinor,
            'net_chargeback_minor'           => $this->netChargebackMinor,
            'chargeback_fee_minor'           => $this->chargebackFeeMinor,
            'currency_code'                  => $this->currencyCode,
            'chargeback_ledger_journal_id'   => $this->chargebackLedgerJournalId,
            'provider_decision_reference'    => $this->providerDecisionReference,
            'correlation_id'                 => $this->correlationId,
            'occurred_at'                    => $this->occurredAt,
        ];
    }
}
