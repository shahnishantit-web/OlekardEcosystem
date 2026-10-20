<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Enums;

enum ServiceStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
}
