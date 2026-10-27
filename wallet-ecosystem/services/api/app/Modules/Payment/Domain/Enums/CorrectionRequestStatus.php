<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum CorrectionRequestStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Posted          = 'posted';
    case PostingFailed   = 'posting_failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Posted, self::Rejected => true,
            default                      => false,
        };
    }

    public function canBeApproved(): bool
    {
        return $this === self::PendingApproval;
    }

    public function canBeRejected(): bool
    {
        return $this === self::PendingApproval;
    }
}
