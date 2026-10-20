<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Exceptions;

final class FxQuoteNotFoundException extends \DomainException
{
    public readonly int $httpStatus;
    public readonly string $errorCode;

    public function __construct(string $quoteId)
    {
        parent::__construct("FX quote [{$quoteId}] not found.");
        $this->httpStatus = 404;
        $this->errorCode  = 'FX_QUOTE_NOT_FOUND';
    }
}
