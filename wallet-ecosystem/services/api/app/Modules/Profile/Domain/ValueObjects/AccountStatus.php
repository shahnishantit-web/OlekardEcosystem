<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\ValueObjects;

use App\Modules\Profile\Domain\Exceptions\InvalidStatusTransitionException;

enum AccountStatus: string
{
    case ACTIVE          = 'active';
    case SUSPENDED       = 'suspended';
    case PENDING_CLOSURE = 'pending_closure';
    case CLOSED          = 'closed';

    private const array VALID_TRANSITIONS = [
        'active'          => ['suspended', 'pending_closure'],
        'suspended'       => ['active'],
        'pending_closure' => ['active', 'closed'],
        'closed'          => [],
    ];

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::VALID_TRANSITIONS[$this->value], true);
    }

    public function assertCanTransitionTo(self $target): void
    {
        if (!$this->canTransitionTo($target)) {
            throw new InvalidStatusTransitionException(
                "Cannot transition from '{$this->value}' to '{$target->value}'."
            );
        }
    }
}
