<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PaymentAllocationStatus: string
{
    case Allocated = 'allocated';
    case Settled   = 'settled';
    case Refunded  = 'refunded';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Settled,
            self::Refunded,
            self::Cancelled => true,
            default         => false,
        };
    }

    /** @return array<self> */
    private static function transitions(): array
    {
        return [
            self::Allocated->value => [self::Settled, self::Refunded, self::Cancelled],
            self::Settled->value   => [],
            self::Refunded->value  => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value] ?? [], strict: true);
    }
}
