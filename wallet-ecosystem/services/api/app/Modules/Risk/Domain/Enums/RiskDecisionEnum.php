<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Enums;

enum RiskDecisionEnum: string
{
    case Allow  = 'allow';
    case Block  = 'block';
    case Review = 'review';
}
