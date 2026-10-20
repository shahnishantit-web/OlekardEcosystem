<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Enums;

enum FundingStatus: string
{
    case Pending              = 'pending';
    case Processing           = 'processing';
    case AwaitingMatch        = 'awaiting_match';
    case PendingProvider      = 'pending_provider';
    case TimeoutPendingReview = 'timeout_pending_review';
    case Completed            = 'completed';
    case Failed               = 'failed';
    case Cancelled            = 'cancelled';

    /** Returns true if the transaction is in a terminal state. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled, self::TimeoutPendingReview => true,
            default => false,
        };
    }

    /** Returns true if the transaction can be cancelled by the customer. */
    public function isCancellable(): bool
    {
        return match ($this) {
            self::Pending, self::AwaitingMatch => true,
            default => false,
        };
    }
}
