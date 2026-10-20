<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Enums;

enum WalletStatus: string
{
    case Pending    = 'pending';
    case Active     = 'active';
    case Frozen     = 'frozen';
    case Suspended  = 'suspended';
    case Restricted = 'restricted';
    case Closed     = 'closed';
}
