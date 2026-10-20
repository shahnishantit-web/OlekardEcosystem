<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Enums;

enum ServiceTransactionStatus: string
{
    case Initiated           = 'initiated';
    case PendingProvider     = 'pending_provider';
    case Completed           = 'completed';
    case Failed              = 'failed';
    case TimeoutPendingReview = 'timeout_pending_review';
    case Abandoned           = 'abandoned';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Abandoned], true);
    }

    public function isResolvable(): bool
    {
        return $this === self::TimeoutPendingReview;
    }
}
