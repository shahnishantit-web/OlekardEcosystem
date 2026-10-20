<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class PinBlockedException extends RuntimeException
{
    public static function make(): self
    {
        return new self('PIN has been blocked due to excessive failed attempts.');
    }
}
