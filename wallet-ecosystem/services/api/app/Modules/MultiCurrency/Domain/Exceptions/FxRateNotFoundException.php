<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Exceptions;

final class FxRateNotFoundException extends \DomainException
{
    public readonly int $httpStatus;
    public readonly string $errorCode;

    public function __construct(string $from, string $to)
    {
        parent::__construct("No active FX rate found for pair [{$from}/{$to}].");
        $this->httpStatus = 422;
        $this->errorCode  = 'FX_RATE_NOT_FOUND';
    }
}
