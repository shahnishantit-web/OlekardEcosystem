<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Exceptions;

use RuntimeException;

final class ReversalNotFoundException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("Reversal [{$id}] not found.");
    }
}
