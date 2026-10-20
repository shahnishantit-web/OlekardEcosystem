<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Contracts;

use App\Modules\Fees\Domain\ValueObjects\FeeCalculationResult;

interface FeesModuleContract
{
    /**
     * Resolve the active fee rule for the given context and calculate the fee amount.
     * Returns a zero-fee result (hasApplicableRule=false) when no rule is configured.
     */
    public function calculateFee(
        string  $transactionType,
        int     $amountMinor,
        string  $currency,
        string  $subjectType,
        ?string $subjectId,
    ): FeeCalculationResult;

    /**
     * Record a fee for a completed transaction and post the double-entry journal via E07.
     *
     * Idempotent on transaction_id: a duplicate call returns the existing fee record ID.
     *
     * @param string $debitAccountRef  Ledger account to debit (e.g. wallet:{customerId})
     * @param string $creditAccountRef Ledger account to credit (e.g. revenue:fees:{currency})
     * @param string|null $feeRuleId    Nullable when no E21 rule covers the transaction type yet
     * @param string $idempotencyKey   UUID v5 derived by caller: Uuid::uuid5(NAMESPACE_URL, $transactionId.':fee')
     * @return string Fee record ID (UUID)
     */
    public function recordFee(
        string  $transactionId,
        int     $amountMinor,
        ?string $feeRuleId,
        string  $currency,
        string $debitAccountRef,
        string $creditAccountRef,
        string $idempotencyKey,
        string $correlationId,
    ): string;

    /**
     * Reverse a previously posted fee via a compensating double-entry journal (entry_type='fee').
     *
     * Idempotent: if the fee is already reversed, this is a no-op.
     *
     * @param string $debitAccountRef  Ledger account to debit for reversal (e.g. revenue:fees:{currency})
     * @param string $creditAccountRef Ledger account to credit for reversal (e.g. wallet:{customerId})
     * @param string $idempotencyKey   UUID v5 derived by caller: Uuid::uuid5(NAMESPACE_URL, $transactionId.':fee:reversal')
     */
    public function reverseFee(
        string $transactionId,
        string $debitAccountRef,
        string $creditAccountRef,
        string $idempotencyKey,
        string $correlationId,
    ): void;
}
