<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Enums;

enum QrCodeStatus: string
{
    case Active   = 'active';
    case Reserved = 'reserved';
    case Used     = 'used';
    case Expired  = 'expired';
    case Inactive = 'inactive';
}
