<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Enums;

enum AlertStatus: string
{
    case Open                    = 'open';
    case UnderInvestigation      = 'under_investigation';
    case Escalated               = 'escalated';
    case ResolvedTruePositive    = 'resolved_true_positive';
    case ResolvedFalsePositive   = 'resolved_false_positive';
    case Closed                  = 'closed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::ResolvedTruePositive,
            self::ResolvedFalsePositive,
            self::Closed => true,
            default      => false,
        };
    }
}
