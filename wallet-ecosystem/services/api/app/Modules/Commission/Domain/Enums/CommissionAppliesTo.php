<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Enums;

enum CommissionAppliesTo: string
{
    case CashIn  = 'cash_in';
    case CashOut = 'cash_out';
    case Both    = 'both';
}
