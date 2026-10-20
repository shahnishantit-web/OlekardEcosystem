<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\Enums;

enum FeeSubjectType: string
{
    case Global   = 'global';
    case Customer = 'customer';
    case Agent    = 'agent';
}
