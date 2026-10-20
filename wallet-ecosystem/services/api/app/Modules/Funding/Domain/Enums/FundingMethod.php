<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Enums;

enum FundingMethod: string
{
    case MobileMoney  = 'mobile_money';
    case BankTransfer = 'bank_transfer';
    case Card         = 'card';
    case Agent        = 'agent';
}
