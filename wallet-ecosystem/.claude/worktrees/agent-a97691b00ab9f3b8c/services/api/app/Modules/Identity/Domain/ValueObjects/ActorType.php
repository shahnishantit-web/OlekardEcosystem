<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObjects;

enum ActorType: string
{
    case USER    = 'USER';
    case SERVICE = 'SERVICE';
    case SYSTEM  = 'SYSTEM';
}
