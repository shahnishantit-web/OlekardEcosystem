<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

/** Typed, service-authorized evidence for an issuance consumer. */
final readonly class PaymentConfirmationEvidence
{
    public function __construct(
        public string $paymentIntentId,
        public string $paymentAttemptId,
        public string $organisationId,
        public string $payerActorId,
        public string $purposeType,
        public string $purposeReference,
        public int $capturedAmountMinor,
        public string $capturedCurrencyCode,
        public string $ledgerJournalId,
        public string $accountingStatus,
    ) {}
}
