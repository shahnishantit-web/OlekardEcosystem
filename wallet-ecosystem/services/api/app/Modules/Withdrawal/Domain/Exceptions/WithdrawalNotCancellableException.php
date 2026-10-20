<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class WithdrawalNotCancellableException extends RuntimeException
{
    public function __construct(string $currentStatus)
    {
        parent::__construct("Withdrawal transaction cannot be cancelled in status: {$currentStatus}");
    }
}
