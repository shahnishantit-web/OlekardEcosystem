<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Enums;

enum ApiClientStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
}
