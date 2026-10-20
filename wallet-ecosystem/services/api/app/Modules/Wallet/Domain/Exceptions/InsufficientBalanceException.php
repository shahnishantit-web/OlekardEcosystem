<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use DomainException;

final class InsufficientBalanceException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct("Insufficient balance: available={$available}, requested={$requested}.");
        $this->errorCode  = 'INSUFFICIENT_BALANCE';
        $this->httpStatus = 422;
    }
}
