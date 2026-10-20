<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Exceptions;

use DomainException;

final class ServiceTransactionNotFoundException extends DomainException
{
    public string $errorCode = 'SERVICE_TRANSACTION_NOT_FOUND';
    public int $httpStatus = 404;
    public function __construct(string $txId)
    {
        parent::__construct("Service transaction [{$txId}] not found.");
    }
}
