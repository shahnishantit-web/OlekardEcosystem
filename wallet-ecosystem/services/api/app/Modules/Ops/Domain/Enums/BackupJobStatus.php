<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Enums;

enum BackupJobStatus: string
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';
}
