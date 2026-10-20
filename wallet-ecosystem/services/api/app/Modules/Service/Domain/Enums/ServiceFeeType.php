<?php

declare(strict_types=1);

namespace App\Modules\Service\Domain\Enums;

enum ServiceFeeType: string
{
    case Flat       = 'flat';
    case Percentage = 'percentage';
}
