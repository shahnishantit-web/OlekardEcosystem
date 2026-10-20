<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Exceptions;

use DomainException;

final class InsufficientBalanceException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;
    public function __construct(int $required, int $available)
    {
        $this->errorCode = 'INSUFFICIENT_BALANCE';
        $this->httpStatus = 422;
        parent::__construct(
            "Insufficient balance. Required: {$required}, available: {$available}."
        );
    }
}
