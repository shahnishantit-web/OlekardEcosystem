<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Exceptions;

final class CurrencyMismatchException extends \DomainException
{
    public readonly int $httpStatus;
    public readonly string $errorCode;

    public function __construct()
    {
        parent::__construct('Source and destination wallets must be in the same currency for this operation. Use the FX conversion flow for cross-currency moves.');
        $this->httpStatus = 422;
        $this->errorCode  = 'CURRENCY_MISMATCH';
    }
}
