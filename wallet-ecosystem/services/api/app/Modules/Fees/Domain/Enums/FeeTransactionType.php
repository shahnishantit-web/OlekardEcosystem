<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Enums;

enum FeeTransactionType: string
{
    case Transfer    = 'transfer';
    case Funding     = 'funding';
    case Withdrawal  = 'withdrawal';
    case BillPayment = 'bill_payment';
    case QrPayment   = 'qr_payment';
    case CashIn      = 'cash_in';
    case CashOut     = 'cash_out';
    case Settlement  = 'settlement';
}
