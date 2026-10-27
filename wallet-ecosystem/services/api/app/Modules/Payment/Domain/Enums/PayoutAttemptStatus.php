<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

// STUB — service layer blocked pending AD-14 resolution
enum PayoutAttemptStatus: string
{
    case Submitted = 'submitted';
    case Pending   = 'pending';
    case Succeeded = 'succeeded';
    case Failed    = 'failed';
    case Unknown   = 'unknown';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed => true,
            default => false,
        };
    }

    public function requiresRetention(): bool
    {
        return match ($this) {
            self::Pending, self::Unknown => true,
            default => false,
        };
    }
}
