<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Enums;

enum AccountType: string
{
    case Asset     = 'asset';
    case Liability = 'liability';
    case Income    = 'income';
    case Expense   = 'expense';
    case Equity    = 'equity';

    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense                   => NormalBalance::Debit,
            self::Liability, self::Income, self::Equity  => NormalBalance::Credit,
        };
    }
}
