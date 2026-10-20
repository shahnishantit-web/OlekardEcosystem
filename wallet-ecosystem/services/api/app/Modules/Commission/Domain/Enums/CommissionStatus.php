<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Enums;

enum CommissionStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Paid     = 'paid';
    case Reversed = 'reversed';
}
