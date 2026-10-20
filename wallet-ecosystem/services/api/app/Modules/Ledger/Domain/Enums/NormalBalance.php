<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Enums;

enum NormalBalance: string
{
    case Debit  = 'debit';
    case Credit = 'credit';
}
