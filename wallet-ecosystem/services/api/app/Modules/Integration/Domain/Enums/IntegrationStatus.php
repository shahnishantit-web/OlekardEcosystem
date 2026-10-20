<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Enums;

enum IntegrationStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
}
