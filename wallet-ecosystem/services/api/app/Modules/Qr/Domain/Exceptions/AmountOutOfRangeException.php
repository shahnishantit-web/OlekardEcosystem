<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Exceptions;

use DomainException;

final class AmountOutOfRangeException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(public readonly int $min, public readonly int $max)
    {
        parent::__construct("Amount must be between {$min} and {$max} minor units.");
        $this->errorCode  = 'AMOUNT_OUT_OF_RANGE';
        $this->httpStatus = 422;
    }
}
