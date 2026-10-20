<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class OtpExpiredException extends RuntimeException
{
    public static function make(): self
    {
        return new self('OTP challenge has expired.');
    }
}
