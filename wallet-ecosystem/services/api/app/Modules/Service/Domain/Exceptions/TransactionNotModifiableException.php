<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Exceptions;

use DomainException;

final class TransactionNotModifiableException extends DomainException
{
    public string $errorCode = 'TRANSACTION_NOT_MODIFIABLE';
    public int $httpStatus = 409;
    public function __construct(string $txId, string $status)
    {
        parent::__construct(
            "Transaction [{$txId}] in status [{$status}] cannot be modified."
        );
    }
}
