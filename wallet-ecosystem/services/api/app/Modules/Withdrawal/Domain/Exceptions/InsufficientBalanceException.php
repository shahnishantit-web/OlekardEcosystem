<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class InsufficientBalanceException extends RuntimeException
{
    public function __construct(int $requested, int $available)
    {
        parent::__construct(
            "Insufficient balance: requested {$requested}, available {$available}."
        );
    }
}
