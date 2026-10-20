<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Enums;

enum FloatTransactionType: string
{
    case Funding = 'funding';
    case CashIn  = 'cash_in';
    case CashOut = 'cash_out';
}
