<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Enums;

enum QrCodeType: string
{
    case Static  = 'static';
    case Dynamic = 'dynamic';
}
