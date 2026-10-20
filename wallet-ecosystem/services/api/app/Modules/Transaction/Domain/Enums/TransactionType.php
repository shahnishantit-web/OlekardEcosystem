<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Enums;

enum TransactionType: string
{
    case Funding    = 'funding';
    case Transfer   = 'transfer';
    case Withdrawal = 'withdrawal';
    case Refund     = 'refund';
    case ServicePayment = 'service_payment';
}
