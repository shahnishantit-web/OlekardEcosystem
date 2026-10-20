<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Enums;

enum AlertType: string
{
    case Velocity        = 'velocity';
    case Watchlist       = 'watchlist';
    case Pattern         = 'pattern';
    case AuthFailure     = 'auth_failure';
    case ThresholdBreach = 'threshold_breach';
    case RapidMovement   = 'rapid_movement';
}
