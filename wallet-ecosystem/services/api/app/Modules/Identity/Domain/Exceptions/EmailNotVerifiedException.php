<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

final class EmailNotVerifiedException extends \DomainException
{
    public string $errorCode;
    public int $httpStatus;

    public static function make(): self
    {
        $e             = new self('Please verify your email address before signing in.');
        $e->errorCode  = 'EMAIL_UNVERIFIED';
        $e->httpStatus = 403;

        return $e;
    }
}
