<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Enums;

enum CommissionRuleStatus: string
{
    case Active     = 'active';
    case Superseded = 'superseded';
    case Expired    = 'expired';
}
