<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Events;

use App\Modules\Platform\Domain\Contracts\IntegrationEventInterface;

final readonly class CorrectionPostedEvent implements IntegrationEventInterface
{
    public function __construct(
        private string $correctionId,
        private string $exceptionId,
        private string $correctionType,
        private int    $amountMinor,
        private string $currencyCode,
        private string $ledgerEntryId,
        private string $reviewedBy,
        private string $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'payment.reconciliation.correction_posted';
    }

    public function occurredAt(): string
    {
        return $this->occurredAt;
    }

    public function correlationId(): string
    {
        return $this->correctionId;
    }

    public function aggregateType(): string
    {
        return 'reconciliation_correction';
    }

    public function aggregateId(): string
    {
        return $this->correctionId;
    }

    public function idempotencyKey(): string
    {
        return "recon:correction:posted:{$this->correctionId}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event'           => $this->eventName(),
            'correction_id'   => $this->correctionId,
            'exception_id'    => $this->exceptionId,
            'correction_type' => $this->correctionType,
            'amount_minor'    => $this->amountMinor,
            'currency_code'   => $this->currencyCode,
            'ledger_entry_id' => $this->ledgerEntryId,
            'reviewed_by'     => $this->reviewedBy,
            'occurred_at'     => $this->occurredAt,
        ];
    }
}
