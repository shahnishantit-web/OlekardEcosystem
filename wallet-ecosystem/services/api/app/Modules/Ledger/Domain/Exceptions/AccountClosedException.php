<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

final class AccountClosedException extends \RuntimeException
{
    public static function forReference(string $reference): self
    {
        return new self("Ledger account '{$reference}' is closed and cannot accept new postings.");
    }
}
