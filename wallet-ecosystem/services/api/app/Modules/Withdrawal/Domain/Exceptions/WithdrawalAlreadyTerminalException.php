<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class WithdrawalAlreadyTerminalException extends RuntimeException
{
    public function __construct(string $currentStatus)
    {
        parent::__construct("Withdrawal transaction is already in terminal status: {$currentStatus}");
    }
}
