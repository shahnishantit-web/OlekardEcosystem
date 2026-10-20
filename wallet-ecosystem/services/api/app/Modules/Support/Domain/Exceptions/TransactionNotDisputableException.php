<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Exceptions;

final class TransactionNotDisputableException extends \DomainException
{
    public readonly string $errorCode;
    public readonly int    $httpStatus;

    public function __construct(string $message = 'Transaction is not in a terminal state and cannot be disputed.')
    {
        parent::__construct($message);
        $this->errorCode  = 'TRANSACTION_NOT_DISPUTABLE';
        $this->httpStatus = 422;
    }
}
