<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class IdentifierAlreadyRegisteredException extends RuntimeException
{
    public static function make(): self
    {
        // Deliberately no identifier in message — identifier disclosure must not occur (BR-014)
        return new self('An account with the provided identifier already exists.');
    }
}
