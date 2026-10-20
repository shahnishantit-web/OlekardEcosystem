<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Enums;

enum TicketStatus: string
{
    case Open       = 'open';
    case InProgress = 'in_progress';
    case Resolved   = 'resolved';
    case Closed     = 'closed';

    public function isTransitionableTo(self $next): bool
    {
        return match ($this) {
            self::Open       => $next === self::InProgress,
            self::InProgress => $next === self::Resolved,
            self::Resolved   => in_array($next, [self::Closed, self::Open], true),
            self::Closed     => $next === self::Open,
        };
    }
}
