<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PayoutReservationStatus: string
{
    case AwaitingApproval = 'awaiting_approval';
    case Approved         = 'approved';
    case Invalidated      = 'invalidated';
    case Rejected         = 'rejected';
    case Expired          = 'expired';
    case Released         = 'released';

    public function isActive(): bool
    {
        return match ($this) {
            self::AwaitingApproval, self::Approved => true,
            default => false,
        };
    }
}
