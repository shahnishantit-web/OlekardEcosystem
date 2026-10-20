<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Enums;

enum TransferStatus: string
{
    case Pending              = 'pending';
    case TimeoutPendingReview = 'timeout_pending_review';
    case Completed            = 'completed';
    case Failed               = 'failed';
    case Cancelled            = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled, self::TimeoutPendingReview => true,
            default => false,
        };
    }

    public function isCancellable(): bool
    {
        return $this === self::Pending;
    }

    public function isConfirmable(): bool
    {
        return $this === self::Pending;
    }
}
