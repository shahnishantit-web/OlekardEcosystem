<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Enums;

enum WalletHoldStatus: string
{
    case Active   = 'active';
    case Released = 'released';
    case Expired  = 'expired';
}
