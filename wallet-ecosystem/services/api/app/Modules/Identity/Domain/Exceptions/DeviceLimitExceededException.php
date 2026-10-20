<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

final class DeviceLimitExceededException extends \RuntimeException
{
    public static function make(): self
    {
        return new self('Device limit exceeded for this user.');
    }
}
