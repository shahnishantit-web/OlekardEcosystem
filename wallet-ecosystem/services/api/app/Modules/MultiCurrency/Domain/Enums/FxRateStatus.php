<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Enums;

enum FxRateStatus: string
{
    case PendingApproval = 'pending_approval';
    case Active          = 'active';
    case Superseded      = 'superseded';
}
