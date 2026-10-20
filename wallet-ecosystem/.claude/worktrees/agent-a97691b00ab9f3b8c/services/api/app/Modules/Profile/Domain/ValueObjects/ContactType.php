<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\ValueObjects;

enum ContactType: string
{
    case EMAIL  = 'email';
    case MOBILE = 'mobile';
}
