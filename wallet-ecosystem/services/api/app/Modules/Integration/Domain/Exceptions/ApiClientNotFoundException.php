<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Exceptions;

use RuntimeException;

final class ApiClientNotFoundException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("API client not found: {$id}");
    }

    public static function forApiKey(string $prefix): self
    {
        return new self("API client not found for key prefix: {$prefix}");
    }
}
