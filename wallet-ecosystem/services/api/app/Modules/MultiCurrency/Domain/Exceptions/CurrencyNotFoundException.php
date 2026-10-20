<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Exceptions;

final class CurrencyNotFoundException extends \DomainException
{
    public readonly int $httpStatus;
    public readonly string $errorCode;

    public function __construct(string $currencyCode)
    {
        parent::__construct("Currency [{$currencyCode}] not found or is not active.");
        $this->httpStatus = 422;
        $this->errorCode  = 'CURRENCY_NOT_FOUND';
    }
}
