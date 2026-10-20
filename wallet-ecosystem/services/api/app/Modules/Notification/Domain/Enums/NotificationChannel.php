<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain\Enums;

enum NotificationChannel: string
{
    case Push  = 'push';
    case Sms   = 'sms';
    case Email = 'email';
    case InApp = 'in_app';
}
