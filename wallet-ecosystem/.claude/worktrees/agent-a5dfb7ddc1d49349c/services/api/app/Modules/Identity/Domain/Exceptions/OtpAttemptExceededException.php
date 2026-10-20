<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class OtpAttemptExceededException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Maximum OTP verification attempts exceeded.');
    }
}
