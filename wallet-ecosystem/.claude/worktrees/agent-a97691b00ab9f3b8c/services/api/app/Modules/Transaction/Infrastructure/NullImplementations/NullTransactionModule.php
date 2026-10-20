<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\NullImplementations;

use App\Exceptions\ModuleNotImplementedException;
use App\Modules\Transaction\Domain\Contracts\TransactionModuleContract;

final class NullTransactionModule implements TransactionModuleContract
{
    public function generateReference(): string
    {
        throw new ModuleNotImplementedException('Transaction', 'generateReference');
    }

    public function status(string $transactionReference): ?string
    {
        throw new ModuleNotImplementedException('Transaction', 'status');
    }
}
