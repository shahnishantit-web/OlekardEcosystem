<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PaymentFulfilmentStatus: string
{
    case Pending          = 'pending';
    case Fulfilled        = 'fulfilled';
    case FulfilmentFailed = 'fulfilment_failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Fulfilled,
            self::FulfilmentFailed => true,
            default                => false,
        };
    }

    /** @return array<self> */
    private static function transitions(): array
    {
        return [
            self::Pending->value          => [self::Fulfilled, self::FulfilmentFailed],
            self::Fulfilled->value        => [],
            self::FulfilmentFailed->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value] ?? [], strict: true);
    }
}
