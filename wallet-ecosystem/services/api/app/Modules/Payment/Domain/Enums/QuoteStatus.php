<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum QuoteStatus: string
{
    case Active     = 'active';
    case Expired    = 'expired';
    case Superseded = 'superseded';
    case Consumed   = 'consumed';
    case Cancelled  = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Superseded,
            self::Consumed,
            self::Cancelled => true,
            default         => false,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Active  => in_array($target, [self::Expired, self::Superseded, self::Consumed, self::Cancelled], true),
            self::Expired => in_array($target, [self::Superseded, self::Consumed, self::Cancelled], true),
            default       => false,
        };
    }
}
