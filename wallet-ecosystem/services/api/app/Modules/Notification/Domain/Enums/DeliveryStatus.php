<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Enums;

enum DeliveryStatus: string
{
    case Bounced = 'bounced';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case PermanentlyFailed = 'permanently_failed';
}
