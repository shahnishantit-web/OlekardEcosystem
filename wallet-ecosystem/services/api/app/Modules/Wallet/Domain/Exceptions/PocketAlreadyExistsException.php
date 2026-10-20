<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Exceptions;

use DomainException;

final class PocketAlreadyExistsException extends DomainException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;

    public function __construct(string $currencyCode)
    {
        parent::__construct("A pocket for currency {$currencyCode} already exists on this wallet.");
        $this->errorCode  = 'POCKET_ALREADY_EXISTS';
        $this->httpStatus = 409;
    }
}
