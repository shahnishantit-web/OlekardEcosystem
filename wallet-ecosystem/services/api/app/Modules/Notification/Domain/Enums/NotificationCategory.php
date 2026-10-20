<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Enums;

enum NotificationCategory: string
{
    case Financial   = 'financial';
    case Security    = 'security';
    case Operational = 'operational';
    case Marketing   = 'marketing';

    public function isMandatory(): bool
    {
        return match ($this) {
            self::Financial, self::Security => true,
            default                         => false,
        };
    }
}
