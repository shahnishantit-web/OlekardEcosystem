<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\ValueObjects;

/** Immutable evidence derived from a Wallet hold and a Ledger-posted debit. */
final readonly class WalletCaptureEvidence
{
    public function __construct(
        public string $captureId,
        public string $holdId,
        public string $ownerActorId,
        public string $paymentAttemptId,
        public string $paymentIntentId,
        public string $organisationId,
        public int $capturedAmountMinor,
        public string $currencyCode,
        public string $ledgerJournalId,
        public string $ledgerIdempotencyKey,
        public string $debitAccountReference,
        public string $creditAccountReference,
        public string $capturedAt,
    ) {}
}
