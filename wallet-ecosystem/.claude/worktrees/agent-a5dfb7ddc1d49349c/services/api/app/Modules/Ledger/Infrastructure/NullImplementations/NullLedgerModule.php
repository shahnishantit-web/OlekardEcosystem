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
}
