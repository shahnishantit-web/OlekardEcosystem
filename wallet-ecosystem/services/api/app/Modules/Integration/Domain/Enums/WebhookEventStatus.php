<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Enums;

enum WebhookEventStatus: string
{
    case Received   = 'received';
    case Processing = 'processing';
    case Processed  = 'processed';
    case Failed     = 'failed';
    case DeadLetter = 'dead_letter';
}
