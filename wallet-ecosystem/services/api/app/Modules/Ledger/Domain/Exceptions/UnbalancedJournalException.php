<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

final class UnbalancedJournalException extends \RuntimeException
{
    public static function withTotals(int $debitTotal, int $creditTotal): self
    {
        return new self("Journal is unbalanced: debit total {$debitTotal} does not equal credit total {$creditTotal}.");
    }
}
