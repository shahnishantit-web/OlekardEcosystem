<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Enums;

enum WalletLimitType: string
{
    case DailyTransactionAmount   = 'daily_transaction_amount';
    case WeeklyTransactionAmount  = 'weekly_transaction_amount';
    case MonthlyTransactionAmount = 'monthly_transaction_amount';
    case SingleTransactionAmount  = 'single_transaction_amount';
    case MaxBalance               = 'max_balance';
}
