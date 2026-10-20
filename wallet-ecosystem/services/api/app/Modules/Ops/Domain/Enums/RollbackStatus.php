<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Enums;

enum RollbackStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Failed     = 'failed';
}
