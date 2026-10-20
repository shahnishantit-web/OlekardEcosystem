<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

final class CurrencyMismatchException extends \RuntimeException
{
    public static function lineMismatch(string $lineCurrency, string $journalCurrency): self
    {
        return new self("Journal line currency '{$lineCurrency}' does not match journal currency '{$journalCurrency}'.");
    }
}
