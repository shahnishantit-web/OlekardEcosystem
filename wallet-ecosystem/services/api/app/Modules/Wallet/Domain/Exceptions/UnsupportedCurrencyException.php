<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use DomainException;

final class UnsupportedCurrencyException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(string $currencyCode)
    {
        parent::__construct("Currency {$currencyCode} is not supported or is disabled.");
        $this->errorCode  = 'UNSUPPORTED_CURRENCY';
        $this->httpStatus = 422;
    }
}
