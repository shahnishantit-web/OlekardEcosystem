<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum ConfigChangeDraftStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Withdrawn       = 'withdrawn';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::PendingApproval => false,
            self::Approved, self::Rejected, self::Withdrawn => true,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PendingApproval => in_array($target, [self::Approved, self::Rejected, self::Withdrawn], true),
            self::Approved, self::Rejected, self::Withdrawn => false,
        };
    }
}
