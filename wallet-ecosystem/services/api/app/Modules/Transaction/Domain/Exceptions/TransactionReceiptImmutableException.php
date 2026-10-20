<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Exceptions;

use RuntimeException;

final class TransactionReceiptImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Transaction receipts are immutable and cannot be modified or deleted.');
    }
}
