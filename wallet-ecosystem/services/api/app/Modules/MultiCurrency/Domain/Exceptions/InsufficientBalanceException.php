<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Exceptions;

final class InsufficientBalanceException extends \DomainException
{
    public readonly int $httpStatus;
    public readonly string $errorCode;

    public function __construct()
    {
        parent::__construct('Insufficient balance for this operation.');
        $this->httpStatus = 422;
        $this->errorCode  = 'INSUFFICIENT_BALANCE';
    }
}
