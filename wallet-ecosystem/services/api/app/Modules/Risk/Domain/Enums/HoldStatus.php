<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Enums;

enum HoldStatus: string
{
    case Active  = 'active';
    case Lifted  = 'lifted';
    case Expired = 'expired';
}
