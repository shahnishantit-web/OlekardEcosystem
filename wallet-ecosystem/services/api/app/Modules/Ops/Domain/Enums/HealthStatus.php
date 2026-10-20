<?php

declare(strict_types=1);

namespace App\Modules\Ops\Domain\Enums;

enum HealthStatus: string
{
    case Healthy   = 'healthy';
    case Degraded  = 'degraded';
    case Unhealthy = 'unhealthy';
}
