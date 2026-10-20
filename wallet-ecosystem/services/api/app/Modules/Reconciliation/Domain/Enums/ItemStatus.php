<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Enums;

enum ItemStatus: string
{
    case Pending   = 'pending';
    case Matched   = 'matched';
    case Unmatched = 'unmatched';
    case Exception = 'exception';
}
