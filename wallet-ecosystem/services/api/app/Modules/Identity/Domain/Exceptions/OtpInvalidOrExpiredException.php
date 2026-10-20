<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class OtpInvalidOrExpiredException extends RuntimeException
{
    public static function invalidCode(): self
    {
        return new self('OTP is invalid.');
    }

    public static function expired(): self
    {
        return new self('OTP has expired.');
    }

    public static function maxAttemptsReached(): self
    {
        return new self('Maximum OTP verification attempts reached.');
    }

    public static function challengeNotFound(): self
    {
        return new self('OTP challenge not found or already consumed.');
    }
}
