<?php

declare(strict_types=1);

namespace App\Modules\Qa\Domain\Enums;

enum ScenarioType: string
{
    case HappyPath    = 'happy_path';
    case Validation   = 'validation';
    case Authorization = 'authorization';
    case Boundary     = 'boundary';
    case Failure      = 'failure';
    case Idempotency  = 'idempotency';
    case Audit        = 'audit';
    case Regression   = 'regression';
}
