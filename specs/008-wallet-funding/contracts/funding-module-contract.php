<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Contracts;

use App\Modules\Funding\Domain\Exceptions\FundingTransactionNotFoundException;

/**
 * FundingModuleContract — the only cross-module interface for funding operations.
 *
 * Consuming modules (E11 Transaction History, E16 Risk, E20 Dispute Management)
 * MUST depend solely on this interface.
 * Direct references to Funding Eloquent models from outside App\Modules\Funding\
 * are prohibited (architecture boundary test).
 *
 * Bound in FundingServiceProvider to FundingService (concrete adapter).
 */
interface FundingModuleContract
{
    /**
     * Returns a funding transaction record by its primary ID.
     *
     * Used by E11 (Transaction History) to enrich transaction feed entries.
     *
     * Returned array keys:
     *   'id'                    => string   (UUID v7)
     *   'wallet_id'             => string   (UUID v7)
     *   'customer_id'           => string   (UUID v7)
     *   'agent_id'              => string|null
     *   'funding_method'        => string   (mobile_money|bank_transfer|card|agent)
     *   'status'                => string   (pending|processing|awaiting_match|completed|failed|cancelled)
     *   'amount'                => int      (gross, minor currency units)
     *   'fee_amount'            => int      (minor currency units)
     *   'net_amount'            => int      (minor currency units)
     *   'currency_code'         => string   (ISO 4217)
     *   'transaction_reference' => string   (UUID v7, cross-module stable reference)
     *   'ledger_journal_id'     => string|null  (set when status = completed)
     *   'receipt_reference'     => string|null  (UUID v7; set when status = completed)
     *   'completed_at'          => string|null  (ISO 8601 UTC)
     *   'created_at'            => string   (ISO 8601 UTC)
     *
     * @throws FundingTransactionNotFoundException if no transaction exists with the given ID
     */
    public function getFundingTransaction(string $fundingTransactionId): array;

    /**
     * Returns true if a funding transaction identified by the cross-module
     * transaction_reference is in completed status.
     *
     * Fast-path check used by downstream modules (e.g., E16 risk checks,
     * E11 history enrichment) without exposing full transaction details.
     *
     * Returns false if no matching transaction is found (not an exception).
     */
    public function isFundingCompleted(string $transactionReference): bool;
}
