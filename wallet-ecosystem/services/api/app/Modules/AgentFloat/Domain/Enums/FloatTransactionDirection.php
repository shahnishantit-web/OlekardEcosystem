<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Domain\Enums;

enum FloatTransactionDirection: string
{
    case Debit  = 'debit';
    case Credit = 'credit';
}
