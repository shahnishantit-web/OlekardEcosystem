<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Exceptions;

use RuntimeException;

final class TransactionStatusHistoryImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Transaction status history records are immutable and cannot be modified or deleted.');
    }
}
