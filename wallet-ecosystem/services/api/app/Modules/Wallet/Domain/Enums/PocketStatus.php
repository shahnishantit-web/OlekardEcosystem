<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain\Enums;

enum PocketStatus: string
{
    case Active   = 'active';
    case Disabled = 'disabled';
}
