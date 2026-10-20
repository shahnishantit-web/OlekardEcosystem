<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

final class EmailVerificationTokenExpiredException extends \DomainException
{
    public string $errorCode;
    public int $httpStatus;

    public static function make(): self
    {
        $e             = new self('This verification link has expired. Please request a new one.');
        $e->errorCode  = 'TOKEN_EXPIRED';
        $e->httpStatus = 422;

        return $e;
    }
}
