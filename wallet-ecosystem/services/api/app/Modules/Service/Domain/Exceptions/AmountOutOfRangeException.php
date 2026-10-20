<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Exceptions;

use DomainException;

final class AmountOutOfRangeException extends DomainException
{
    public string $errorCode = 'AMOUNT_OUT_OF_RANGE';
    public int $httpStatus = 422;
    public function __construct(int $amount, int $min, int $max)
    {
        parent::__construct(
            "Amount [{$amount}] is out of the allowed range [{$min}–{$max}]."
        );
    }
}
