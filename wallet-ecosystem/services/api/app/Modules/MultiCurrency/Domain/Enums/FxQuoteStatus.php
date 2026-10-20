<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Enums;

enum FxQuoteStatus: string
{
    case Quoted    = 'quoted';
    case Confirmed = 'confirmed';
    case Expired   = 'expired';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Confirmed, self::Expired, self::Cancelled => true,
            default => false,
        };
    }
}
