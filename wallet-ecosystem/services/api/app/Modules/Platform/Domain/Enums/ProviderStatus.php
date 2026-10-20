<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum ProviderStatus: string
{
    case UP       = 'up';
    case DEGRADED = 'degraded';
    case DOWN     = 'down';
    case UNKNOWN  = 'unknown';
    case SUCCESS  = 'success'; // used in ProviderResponse only
    case FAILURE  = 'failure'; // used in ProviderResponse only
}
