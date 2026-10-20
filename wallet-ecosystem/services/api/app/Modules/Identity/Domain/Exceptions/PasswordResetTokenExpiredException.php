<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

final class PasswordResetTokenExpiredException extends \DomainException
{
    public string $errorCode;
    public int $httpStatus;

    public static function make(): self
    {
        $e             = new self('This password reset link has expired. Please request a new one.');
        $e->errorCode  = 'TOKEN_EXPIRED';
        $e->httpStatus = 422;

        return $e;
    }
}
