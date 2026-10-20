<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain\Enums;

enum AuditOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Denied  = 'denied';
}
