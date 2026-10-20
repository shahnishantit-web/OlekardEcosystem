<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Enums;

enum AuditActorType: string
{
    case Customer = 'customer';
    case Agent    = 'agent';
    case Admin    = 'admin';
    case System   = 'system';
}
