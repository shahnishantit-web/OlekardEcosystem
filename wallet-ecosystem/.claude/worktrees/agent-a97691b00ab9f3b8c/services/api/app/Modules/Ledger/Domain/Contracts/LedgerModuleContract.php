<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Contracts;

interface LedgerModuleContract
{
    /**
     * Post a double-entry ledger record.
     * Returns the ledger entry UUID.
     * LedgerEntryRequest is defined by E07; Platform Foundation registers a
     * NullLedgerModule stub that throws ModuleNotImplementedException.
     */
    public function postEntry(array $request): string;

    /** Returns true if a ledger entry for the given transaction reference already exists. */
    public function entryExists(string $transactionReference): bool;
}
