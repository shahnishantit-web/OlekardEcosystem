<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Enums;

enum BackupType: string
{
    case Full        = 'full';
    case Incremental = 'incremental';
}
