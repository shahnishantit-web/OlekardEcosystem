<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum ProviderStatementStatus: string
{
    case PendingMatch     = 'pending_match';
    case Matching         = 'matching';
    case Matched          = 'matched';
    case PartiallyMatched = 'partially_matched';
    case ExceptionRaised  = 'exception_raised';
    case Closed           = 'closed';

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    public function allowsQualification(): bool
    {
        return match ($this) {
            self::Matched, self::PartiallyMatched => true,
            default                               => false,
        };
    }
}
