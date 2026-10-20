<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Enums;

enum CommissionRuleType: string
{
    case Flat       = 'flat';
    case Percentage = 'percentage';
}
