<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Enums;

enum ReportJobStatus: string
{
    case Requested  = 'requested';
    case Processing = 'processing';
    case Complete   = 'complete';
    case Failed     = 'failed';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Complete, self::Failed => true,
            default                      => false,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Requested  => $next === self::Processing,
            self::Processing => $next === self::Complete || $next === self::Failed,
            default          => false,
        };
    }
}
