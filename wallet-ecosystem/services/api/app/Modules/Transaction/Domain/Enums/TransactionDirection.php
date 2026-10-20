<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Enums;

enum TransactionDirection: string
{
    case Debit  = 'debit';
    case Credit = 'credit';
}
