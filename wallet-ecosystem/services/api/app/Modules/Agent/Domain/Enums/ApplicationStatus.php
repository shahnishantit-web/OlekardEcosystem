<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Enums;

enum ApplicationStatus: string
{
    case Draft         = 'draft';
    case InProgress    = 'in_progress';
    case Submitted     = 'submitted';
    case KycInProgress = 'kyc_in_progress';
    case KycPassed     = 'kyc_passed';
    case KycFailed     = 'kyc_failed';
    case UnderReview   = 'under_review';
    case Approved      = 'approved';
    case Rejected      = 'rejected';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::InProgress], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::KycFailed], true);
    }
}
