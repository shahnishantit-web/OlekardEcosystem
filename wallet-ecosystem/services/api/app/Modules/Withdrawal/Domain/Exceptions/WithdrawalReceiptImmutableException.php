<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class WithdrawalReceiptImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Withdrawal receipts are immutable after creation.');
    }
}
