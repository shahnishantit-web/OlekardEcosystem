<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PaymentRefundStatus: string
{
    case Requested       = 'requested';
    case PendingApproval = 'pending_approval';
    case Processing      = 'processing';
    case Succeeded       = 'succeeded';
    case Failed          = 'failed';
    case Cancelled       = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded,
            self::Failed,
            self::Cancelled => true,
            default         => false,
        };
    }
}
