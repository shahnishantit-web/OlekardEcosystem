<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Enums;

enum DisputeStatus: string
{
    case Raised               = 'raised';
    case EvidenceSubmitted    = 'evidence_submitted';
    case UnderInvestigation   = 'under_investigation';
    case PendingConfirmation  = 'pending_confirmation';
    case Approved             = 'approved';
    case Rejected             = 'rejected';
    case Refunded             = 'refunded';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Refunded => true,
            default                        => false,
        };
    }

    public function isTransitionableTo(self $next): bool
    {
        return match ($this) {
            self::Raised             => in_array($next, [self::EvidenceSubmitted, self::UnderInvestigation], true),
            self::EvidenceSubmitted  => $next === self::UnderInvestigation,
            self::UnderInvestigation => in_array($next, [self::PendingConfirmation, self::Approved, self::Rejected], true),
            self::PendingConfirmation => $next === self::Approved,
            self::Approved           => $next === self::Refunded,
            self::Rejected, self::Refunded => false,
        };
    }
}
