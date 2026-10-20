<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Exceptions;

use RuntimeException;

final class IntegrationNotFoundException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("Integration not found: {$id}");
    }
}
