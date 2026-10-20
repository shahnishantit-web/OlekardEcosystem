<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Exceptions;

final class FxQuoteExpiredException extends \DomainException
{
    public readonly int $httpStatus;
    public readonly string $errorCode;

    public function __construct(string $quoteId)
    {
        parent::__construct("FX quote [{$quoteId}] has expired. Please request a new quote.");
        $this->httpStatus = 422;
        $this->errorCode  = 'FX_QUOTE_EXPIRED';
    }
}
