<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Enums;

enum CommissionPayoutStatus: string
{
    case Approved   = 'approved';
    case Completed  = 'completed';
    case Deferred   = 'deferred';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';
}
