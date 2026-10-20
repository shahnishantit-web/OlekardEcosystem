<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

final class ConfigurationConflictException extends RuntimeException
{
    public static function staleVersion(string $key): self
    {
        return new self("Configuration key '{$key}' has been modified by another operation. Refresh and retry.");
    }

    public static function belowMinimum(string $key, int|float $minimum): self
    {
        return new self("Value for '{$key}' cannot be set below the platform security minimum of {$minimum}.");
    }

    public static function invalidValue(string $key, string $reason): self
    {
        return new self("Invalid value for configuration key '{$key}': {$reason}.");
    }
}
