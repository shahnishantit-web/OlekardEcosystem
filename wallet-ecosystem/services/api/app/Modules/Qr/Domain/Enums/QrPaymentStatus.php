<?php

declare(strict_types=1);

namespace App\Modules\Qr\Domain\Enums;

enum QrPaymentStatus: string
{
    case Initiated = 'initiated';
    case Completed = 'completed';
    case Failed    = 'failed';
}
