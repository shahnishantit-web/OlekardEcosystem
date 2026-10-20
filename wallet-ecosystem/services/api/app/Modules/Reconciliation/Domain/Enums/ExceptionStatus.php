<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Enums;

enum ExceptionStatus: string
{
    case Open          = 'open';
    case Investigating = 'investigating';
    case Resolved      = 'resolved';
    case WrittenOff    = 'written_off';
}
