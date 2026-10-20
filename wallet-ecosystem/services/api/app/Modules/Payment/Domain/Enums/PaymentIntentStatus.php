<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum PaymentIntentStatus: string
{
    case Pending              = 'pending';
    case Processing           = 'processing';
    case AwaitingAction       = 'awaiting_action';
    case RequiresConfirmation = 'requires_confirmation';
    case Succeeded            = 'succeeded';
    case Failed               = 'failed';
    case Cancelled            = 'cancelled';
    case Expired              = 'expired';
    case Refunding            = 'refunding';
    case PartiallyRefunded    = 'partially_refunded';
    case Refunded             = 'refunded';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded,
            self::Failed,
            self::Cancelled,
            self::Expired,
            self::Refunded => true,
            default        => false,
        };
    }

    /** @return array<self> */
    private static function transitions(): array
    {
        return [
            self::Pending->value              => [self::Processing, self::Cancelled, self::Expired],
            self::Processing->value           => [self::AwaitingAction, self::RequiresConfirmation, self::Succeeded, self::Failed, self::Cancelled],
            self::AwaitingAction->value       => [self::RequiresConfirmation, self::Processing, self::Cancelled, self::Expired],
            self::RequiresConfirmation->value => [self::Succeeded, self::Failed],
            self::Succeeded->value            => [self::Refunding],
            self::Refunding->value            => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded->value    => [self::Refunding, self::Refunded],
            // terminal states have no outgoing transitions
            self::Failed->value               => [],
            self::Cancelled->value            => [],
            self::Expired->value              => [],
            self::Refunded->value             => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value] ?? [], strict: true);
    }
}
