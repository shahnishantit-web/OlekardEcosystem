<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Enums;

enum WithdrawalChannel: string
{
    case MobileMoney  = 'mobile_money';
    case BankTransfer = 'bank_transfer';
}
