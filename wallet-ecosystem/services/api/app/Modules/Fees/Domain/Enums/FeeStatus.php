<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Enums;

enum FeeStatus: string
{
    case Posted   = 'posted';
    case Reversed = 'reversed';
}
