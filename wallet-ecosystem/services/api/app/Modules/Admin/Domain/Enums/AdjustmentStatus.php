<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Enums;

enum AdjustmentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Posted          = 'posted';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Posted => true,
            default                      => false,
        };
    }
}
