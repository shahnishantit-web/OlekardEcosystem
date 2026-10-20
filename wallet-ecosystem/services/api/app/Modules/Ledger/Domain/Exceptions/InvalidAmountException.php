<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Exceptions;

final class InvalidAmountException extends \RuntimeException
{
    public static function nonPositive(int $amount): self
    {
        return new self("Amount must be greater than zero; got {$amount}.");
    }
}
