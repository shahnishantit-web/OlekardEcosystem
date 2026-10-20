<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class PinComplexityException extends RuntimeException
{
    public static function sequential(): self
    {
        return new self('PIN must not be a sequential pattern.');
    }

    public static function repeated(): self
    {
        return new self('PIN must not use repeated digits.');
    }

    public static function common(): self
    {
        return new self('PIN must not be a commonly used value.');
    }

    public static function invalidLength(int $required): self
    {
        return new self("PIN must be exactly {$required} digits.");
    }
}
