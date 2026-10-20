<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\NullImplementations;

use App\Exceptions\ModuleNotImplementedException;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;

final class NullLedgerModule implements LedgerModuleContract
{
    public function postEntry(array $request): string
    {
        throw new ModuleNotImplementedException('Ledger', 'postEntry');
    }

    public function entryExists(string $transactionReference): bool
    {
        throw new ModuleNotImplementedException('Ledger', 'entryExists');
    }

    public function getBalance(string $ledgerAccountReference): int
    {
        throw new ModuleNotImplementedException('Ledger', 'getBalance');
    }

    public function registerAccount(
        string $ledgerAccountReference,
        string $accountType,
        string $currencyCode,
        string $ownerReference,
        string $ownerType
    ): void {
        throw new ModuleNotImplementedException('Ledger', 'registerAccount');
    }

    public function getAccountHistory(
        string $ledgerAccountReference,
        int $page,
        int $perPage,
        array $filters = [],
    ): array {
        throw new ModuleNotImplementedException('Ledger', 'getAccountHistory');
    }

    public function getTransactionHistory(string $transactionReference): array
    {
        throw new ModuleNotImplementedException('Ledger', 'getTransactionHistory');
    }
}
