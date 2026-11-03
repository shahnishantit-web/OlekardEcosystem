<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Ledger\Domain\ValueObjects\PostedJournalEvidence;

/** Applies the approved wallet payment posting semantics: one exact debit and one exact credit. */
final class LedgerPaymentEvidenceVerifier
{
    public function matches(
        PostedJournalEvidence $evidence,
        string $journalId,
        string $intentId,
        string $currencyCode,
        int $capturedAmountMinor,
        string $idempotencyKey,
        string $debitAccountReference,
        string $creditAccountReference,
    ): bool {
        if ($evidence->journalId !== $journalId || $evidence->status !== 'posted'
            || $evidence->entryType !== 'debit' || $evidence->transactionReference !== $intentId
            || $evidence->currencyCode !== $currencyCode || $evidence->idempotencyKey !== $idempotencyKey
            || count($evidence->lines) !== 2) return false;

        $debits = array_filter($evidence->lines, static fn (array $line): bool =>
            $line['direction'] === 'debit' && $line['account_reference'] === $debitAccountReference
            && $line['amount_minor'] === $capturedAmountMinor && $line['currency_code'] === $currencyCode
        );
        $credits = array_filter($evidence->lines, static fn (array $line): bool =>
            $line['direction'] === 'credit' && $line['account_reference'] === $creditAccountReference
            && $line['amount_minor'] === $capturedAmountMinor && $line['currency_code'] === $currencyCode
        );
        return count($debits) === 1 && count($credits) === 1;
    }
}
