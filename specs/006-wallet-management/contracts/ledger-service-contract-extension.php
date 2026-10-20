<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Contracts;

/**
 * LedgerModuleContract — extension required by E06 (FR-E06-018).
 *
 * This file documents the method that must be added to LedgerModuleContract
 * as part of E06 implementation. The NullLedgerModule stub must also implement
 * this method (throwing ModuleNotImplementedException until E07 is implemented).
 *
 * NOTE: This is a contract specification file only. The actual implementation
 * goes in:
 *   wallet-ecosystem/services/api/app/Modules/Ledger/Domain/Contracts/LedgerModuleContract.php
 */
interface LedgerModuleContractExtension
{
    /**
     * Returns the settled ledger balance for the given account reference.
     *
     * Balance = SUM(credit entries) − SUM(debit entries) for all SETTLED entries
     * associated with the ledger_account_reference.
     *
     * Returns 0 if the account has no entries.
     * Amount is in the currency's minor units (integer).
     *
     * E06 calls this within a SELECT FOR UPDATE transaction scope when computing
     * available_balance for debit enforcement (FR-E06-018, FR-E06-025).
     *
     * Throws ModuleNotImplementedException until E07 is implemented.
     */
    public function getBalance(string $ledgerAccountReference): int;
}
