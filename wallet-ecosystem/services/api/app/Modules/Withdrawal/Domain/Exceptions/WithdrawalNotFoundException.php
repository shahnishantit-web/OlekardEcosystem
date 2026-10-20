<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class WithdrawalNotFoundException extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct("Withdrawal transaction not found: {$id}");
    }
}
