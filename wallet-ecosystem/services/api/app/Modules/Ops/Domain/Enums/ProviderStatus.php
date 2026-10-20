<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Enums;

enum ProviderStatus: string
{
    case Up       = 'up';
    case Degraded = 'degraded';
    case Down     = 'down';
}
