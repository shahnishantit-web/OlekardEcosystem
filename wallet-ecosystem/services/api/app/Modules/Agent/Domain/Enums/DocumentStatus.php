<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Enums;

enum DocumentStatus: string
{
    case PendingReview = 'pending_review';
    case Accepted      = 'accepted';
    case Rejected      = 'rejected';
    case Quarantined   = 'quarantined';

    public function isPersisted(): bool
    {
        return $this !== self::Quarantined;
    }
}
