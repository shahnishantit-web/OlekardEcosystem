<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Enums;

enum JournalStatus: string
{
    case Posted = 'posted';
}
