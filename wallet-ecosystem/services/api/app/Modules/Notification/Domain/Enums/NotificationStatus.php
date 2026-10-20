<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Enums;

enum NotificationStatus: string
{
    case Queued             = 'queued';
    case Dispatching        = 'dispatching';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered          = 'delivered';
    case Failed             = 'failed';
}
