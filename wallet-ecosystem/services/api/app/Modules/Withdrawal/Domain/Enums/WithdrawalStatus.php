<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Enums;

enum WithdrawalStatus: string
{
    case Pending              = 'pending';
    case Processing           = 'processing';
    case PendingProvider      = 'pending_provider';
    case TimeoutPendingReview = 'timeout_pending_review';
    case Completed            = 'completed';
    case Failed               = 'failed';
    case Cancelled            = 'cancelled';
}
