<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class RefreshTokenReuseException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Refresh token reuse detected. All sessions in this family have been revoked.');
    }
}
