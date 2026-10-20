<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Enums;

enum AlertStatus: string
{
    case Firing   = 'firing';
    case Resolved = 'resolved';
}
