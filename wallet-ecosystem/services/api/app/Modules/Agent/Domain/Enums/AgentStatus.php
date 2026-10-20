<?php

declare(strict_types=1);

namespace App\Modules\Agent\Domain\Enums;

enum AgentStatus: string
{
    case Active    = 'active';
    case Suspended = 'suspended';

    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
