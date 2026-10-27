<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Adapter-level outcome only; independent of collection and settlement states. */
enum PayoutOutcome: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
