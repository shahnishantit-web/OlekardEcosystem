<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Contracts;

/**
 * LedgerModuleContract — the only cross-module interface for ledger operations (FR-E07-042, FR-E07-043).
 *
 * Consuming epics (E06, E08, E09, E10, E11, E21) MUST depend solely on this interface.
 * Direct references to Ledger Eloquent models (JournalModel, JournalLineModel, LedgerAccountModel)
 * from outside App\Modules\Ledger\ are prohibited (FR-E07-046).
 *
 * The three original methods (postEntry, entryExists, getBalance) were stubs in E06.
 * E07 provides the concrete implementation and adds registerAccount() and getAccountHistory().
 *
 * NullLedgerModule throws ModuleNotImplementedException for all five methods until E07 is live.
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
     *   'transaction_reference' => string|null  (optional; cross-module reference e.g. E11 transaction ID)
     *   'correlation_id'        => string   (required; request correlation ID)
     *   'description'           => string|null  (optional; human-readable reason)
     *   'lines'                 => array    (required; see PostEntryRequest contract)
     *
     * Each element of 'lines':
     *   'ledger_account_reference' => string  (UUID v7 opaque reference)
     *   'direction'                => string  ('debit' or 'credit')
     *   'amount'                   => int     (positive integer; minor currency units)
     *
     * Returns the journal ID (UUID v7 string) of the created (or already-existing idempotent) journal.
     *
     * Throws on validation failure (UnbalancedJournalException, CurrencyMismatchException,
     * InvalidAmountException, AccountClosedException). Returns existing journal ID on idempotent replay.
     *
     * Caller MUST supply a balanced set of lines: SUM(debit amounts) == SUM(credit amounts).
     * See FR-E07-044 for the full request shape.
     */
    public function postEntry(array $request): string;

    /**
     * Returns true if any journal with the given transaction_reference exists in 'posted' status.
     *
     * Fast path for upstream services (e.g., E11) to verify whether a transaction has already
     * been fully posted before attempting a posting. Does not require ledger.post permission.
     * See FR-E07-027.
     */
    public function entryExists(string $transactionReference): bool;

    /**
     * Returns the authoritative settled balance (integer, minor currency units) for the account
     * identified by the given ledger_account_reference.
     *
     * For normal_balance = credit (customer pockets, income, liability accounts):
     *   balance = SUM(credits) - SUM(debits)
     *
     * For normal_balance = debit (asset, expense accounts):
     *   balance = SUM(debits) - SUM(credits)
     *
     * Returns 0 for an unknown reference (never registered, never posted to).
     *
     * MUST be called on the same DB connection as any enclosing SELECT FOR UPDATE transaction
     * to guarantee a consistent snapshot (FR-E07-023).
     * See FR-E07-020, FR-E07-021, FR-E07-022.
     */
    public function getBalance(string $ledgerAccountReference): int;

    /**
     * Explicitly register a ledger account before its first posting.
     *
     * Consuming modules (E08+) SHOULD call this rather than relying on auto-registration
     * to ensure the correct account_type and normal_balance are set (FR-E07-003; TBD-E07-002).
     *
     * @param string $ledgerAccountReference  UUID v7 pre-assigned by the consuming module
     * @param string $accountType             'liability' | 'asset' | 'income' | 'expense' | 'equity'
     * @param string $currencyCode            ISO 4217 currency code
     * @param string $ownerReference          UUID of the owning entity (e.g., wallet pocket id)
     * @param string $ownerType               'customer_pocket' | 'system_float' | 'system_revenue'
     *                                        | 'system_fee' | 'agent_commission' | 'system_suspense'
     *
     * Idempotent: if the account is already registered with identical parameters, this is a no-op.
     * Throws if an account with the same reference exists but with different account_type or currency.
     * See FR-E07-043.
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
     * Each element of the returned array contains:
     *   'journal_id'            => string   (UUID v7)
     *   'entry_type'            => string
     *   'direction'             => string   ('debit' | 'credit')
     *   'amount'                => int      (minor currency units)
     *   'currency_code'         => string
     *   'transaction_reference' => string|null
     *   'posted_at'             => string   (ISO 8601 UTC)
     *
     * Returns the platform-standard paginated envelope:
     *   'data'  => array  (page of line records)
     *   'meta'  => ['total' => int, 'page' => int, 'per_page' => int, 'pages' => int]
     *
     * See FR-E07-032, FR-E07-043.
     */
    public function getAccountHistory(
        string $ledgerAccountReference,
        int $page,
        int $perPage
    ): array;
}
