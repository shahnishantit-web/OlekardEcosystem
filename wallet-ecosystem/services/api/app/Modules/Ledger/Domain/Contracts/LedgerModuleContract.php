<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Contracts;

/**
 * LedgerModuleContract — the only cross-module interface for ledger operations (FR-E07-042, FR-E07-043).
 *
 * Consuming epics (E06, E08, E09, E10, E11, E21) MUST depend solely on this interface.
 * Direct references to Ledger Eloquent models (JournalModel, JournalLineModel, LedgerAccountModel)
 * from outside App\Modules\Ledger\ are prohibited (FR-E07-046).
 */
interface LedgerModuleContract
{
    /**
     * Post a balanced double-entry journal.
     *
     * The request array MUST contain:
     *   'idempotency_key'       => string   (required; client-supplied UUID)
     *   'entry_type'            => string   (required; one of: debit|credit|fee|revenue|commission)
     *   'currency_code'         => string   (required; ISO 4217)
     *   'transaction_reference' => string|null  (optional)
     *   'correlation_id'        => string   (required)
     *   'description'           => string|null  (optional)
     *   'lines'                 => array    (required; min 2 elements)
     *
     * Each element of 'lines':
     *   'ledger_account_reference' => string
     *   'direction'                => string  ('debit' or 'credit')
     *   'amount'                   => int     (positive integer; minor currency units)
     *
     * Returns the journal ID (UUID v7) of the created or idempotent-replay journal.
     */
    public function postEntry(array $request): string;

    /**
     * Returns true if any journal with the given transaction_reference exists.
     */
    public function entryExists(string $transactionReference): bool;

    /** Posted journal lines for one transaction; pending transactions return no lines. */
    public function getTransactionHistory(string $transactionReference): array;

    /**
     * Returns the authoritative settled balance (integer, minor units) for the account.
     *
     * For credit-normal accounts: balance = SUM(credits) - SUM(debits)
     * For debit-normal accounts:  balance = SUM(debits)  - SUM(credits)
     *
     * Returns 0 for unknown references.
     */
    public function getBalance(string $ledgerAccountReference): int;

    /**
     * Explicitly register a ledger account before its first posting.
     *
     * Idempotent: if the account is already registered with identical parameters, this is a no-op.
     *
     * @param string $ledgerAccountReference UUID v7 pre-assigned by the consuming module
     * @param string $accountType            'liability'|'asset'|'income'|'expense'|'equity'
     * @param string $currencyCode           ISO 4217 currency code
     * @param string $ownerReference         UUID of the owning entity
     * @param string $ownerType              'customer_pocket'|'system_float'|'system_revenue'|
     *                                       'system_fee'|'agent_commission'|'system_suspense'
     */
    public function registerAccount(
        string $ledgerAccountReference,
        string $accountType,
        string $currencyCode,
        string $ownerReference,
        string $ownerType
    ): void;

    /**
     * Returns paginated journal line history for the given ledger account reference.
     *
     * Returns: ['data' => [...], 'meta' => ['total', 'page', 'per_page', 'pages']]
     */
    public function getAccountHistory(
        string $ledgerAccountReference,
        int $page,
        int $perPage,
        array $filters = [],
    ): array;
}
