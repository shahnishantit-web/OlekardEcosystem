<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

// STUB — service layer blocked pending AD-10 and LC14 resolution
enum PayoutSettlementReconciliationStatus: string
{
    case Pending          = 'pending';
    case Matched          = 'matched';
    case Discrepancy      = 'discrepancy';
    case ExceptionRaised  = 'exception_raised';
}
