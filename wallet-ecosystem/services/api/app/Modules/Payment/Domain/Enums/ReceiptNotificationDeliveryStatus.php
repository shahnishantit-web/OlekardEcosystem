<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum ReceiptNotificationDeliveryStatus: string
{
    case Dispatched = 'dispatched';
    case Failed     = 'failed';
    case Skipped    = 'skipped';
}
