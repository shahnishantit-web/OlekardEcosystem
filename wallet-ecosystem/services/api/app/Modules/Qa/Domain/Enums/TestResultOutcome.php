<?php

declare(strict_types=1);

namespace App\Modules\Qa\Domain\Enums;

enum TestResultOutcome: string
{
    case Passed  = 'passed';
    case Failed  = 'failed';
    case Skipped = 'skipped';
    case Error   = 'error';

    public function isPassing(): bool
    {
        return $this === self::Passed;
    }
}
