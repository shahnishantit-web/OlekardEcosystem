<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Exceptions;

final class CurrencyWalletAlreadyExistsException extends \DomainException
{
    public readonly int $httpStatus;
    public readonly string $errorCode;

    public function __construct(string $currencyCode)
    {
        parent::__construct("A wallet in currency [{$currencyCode}] already exists for this customer.");
        $this->httpStatus = 409;
        $this->errorCode  = 'CURRENCY_WALLET_ALREADY_EXISTS';
    }
}
