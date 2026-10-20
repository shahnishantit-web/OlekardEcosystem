<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Enums;

enum AgentType: string
{
    case Individual = 'individual';
    case Business   = 'business';
}
