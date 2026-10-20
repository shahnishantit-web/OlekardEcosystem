<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum ChangeRequestStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Withdrawn       = 'withdrawn';
    case Failed          = 'failed';

    public function isTerminal(): bool
    {
        return match($this) {
            self::PendingApproval => false,
            default               => true,
        };
    }
}
