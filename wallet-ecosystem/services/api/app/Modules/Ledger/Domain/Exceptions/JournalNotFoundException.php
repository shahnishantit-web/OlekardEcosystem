<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

final class JournalNotFoundException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("Journal '{$id}' not found.");
    }
}
