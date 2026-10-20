<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Enums;

enum TicketPriority: string
{
    case Low      = 'low';
    case Medium   = 'medium';
    case High     = 'high';
    case Critical = 'critical';
}
