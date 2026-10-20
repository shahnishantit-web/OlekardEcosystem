<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Enums;

enum LedgerAccountStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}
