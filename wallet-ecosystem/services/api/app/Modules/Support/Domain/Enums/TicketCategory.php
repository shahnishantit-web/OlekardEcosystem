<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Enums;

enum TicketCategory: string
{
    case Account     = 'account';
    case Transaction = 'transaction';
    case Kyc         = 'kyc';
    case Float       = 'float';
    case Commission  = 'commission';
    case Technical   = 'technical';
    case General     = 'general';
}
