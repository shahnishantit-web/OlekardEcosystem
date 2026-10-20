<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Enums;

enum FeeRuleStatus: string
{
    case Active      = 'active';
    case Expired     = 'expired';
    case Deactivated = 'deactivated';
    case Superseded  = 'superseded';
}
