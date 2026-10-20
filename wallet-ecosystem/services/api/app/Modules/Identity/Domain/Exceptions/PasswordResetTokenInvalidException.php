<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

final class PasswordResetTokenInvalidException extends \DomainException
{
    public string $errorCode;
    public int $httpStatus;

    public static function make(): self
    {
        $e             = new self('This password reset link is invalid or has already been used.');
        $e->errorCode  = 'TOKEN_INVALID';
        $e->httpStatus = 422;

        return $e;
    }
}
