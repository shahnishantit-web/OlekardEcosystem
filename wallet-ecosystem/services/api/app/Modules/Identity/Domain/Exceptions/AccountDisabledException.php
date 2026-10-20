<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

final class AccountDisabledException extends \DomainException
{
    public string $errorCode;
    public int $httpStatus;

    public static function make(): self
    {
        $e             = new self('This account has been disabled. Please contact your administrator.');
        $e->errorCode  = 'ACCOUNT_DISABLED';
        $e->httpStatus = 403;

        return $e;
    }
}
