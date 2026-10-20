<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Domain\Enums;

enum ReversalStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Executed        = 'executed';
    case Failed          = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Executed, self::Failed => true,
            default                                       => false,
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::PendingApproval, self::Approved => true,
            default                               => false,
        };
    }
}
