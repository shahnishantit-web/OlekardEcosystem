<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum BankLifecycleStatus: string
{
    case Draft     = 'draft';
    case Active    = 'active';
    case Suspended = 'suspended';
    case Retired   = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Active    => 'Active',
            self::Suspended => 'Suspended',
            self::Retired   => 'Retired',
        };
    }

    /** @return array<BankLifecycleStatus> */
    public function allowedTransitionsFrom(): array
    {
        return match ($this) {
            self::Draft     => [self::Active],
            self::Active    => [self::Suspended, self::Retired],
            self::Suspended => [self::Active, self::Retired],
            self::Retired   => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitionsFrom(), true);
    }
}
