<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Enums;

enum AuditEventCategory: string
{
    case Authentication = 'authentication';
    case Authorization  = 'authorization';
    case Wallet         = 'wallet';
    case Transaction    = 'transaction';
    case Kyc            = 'kyc';
    case Admin          = 'admin';
    case Config         = 'config';
    case Security       = 'security';
}
