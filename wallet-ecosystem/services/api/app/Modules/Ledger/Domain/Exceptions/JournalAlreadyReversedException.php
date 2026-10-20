<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

final class JournalAlreadyReversedException extends \RuntimeException
{
    public static function forId(string $journalId): self
    {
        return new self("Journal '{$journalId}' has already been reversed.");
    }
}
