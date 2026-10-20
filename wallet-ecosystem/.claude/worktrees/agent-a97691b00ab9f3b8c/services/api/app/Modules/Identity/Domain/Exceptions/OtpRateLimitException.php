<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class OtpRateLimitException extends RuntimeException
{
    public static function resendCooldown(int $secondsRemaining): self
    {
        return new self("OTP resend cooldown active. Retry after {$secondsRemaining} seconds.");
    }

    public static function hourlySendLimitReached(): self
    {
        return new self('Hourly OTP send limit reached for this identifier.');
    }
}
