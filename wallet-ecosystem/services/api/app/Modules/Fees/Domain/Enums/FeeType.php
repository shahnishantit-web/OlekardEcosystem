<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Enums;

enum FeeType: string
{
    case Fixed      = 'fixed';
    case Percentage = 'percentage';
}
