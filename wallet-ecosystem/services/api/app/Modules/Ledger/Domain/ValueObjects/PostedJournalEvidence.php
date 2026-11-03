<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\ValueObjects;

/** Immutable evidence returned only for a journal that Ledger has posted. */
final readonly class PostedJournalEvidence
{
    /** @param list<array{account_reference:string,direction:string,amount_minor:int,currency_code:string}> $lines */
    public function __construct(
        public string $journalId,
        public string $status,
        public string $entryType,
        public string $transactionReference,
        public string $idempotencyKey,
        public string $currencyCode,
        public array $lines,
    ) {}
}
