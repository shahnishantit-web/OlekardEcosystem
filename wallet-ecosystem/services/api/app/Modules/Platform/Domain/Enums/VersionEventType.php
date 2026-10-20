<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum VersionEventType: string
{
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Withdrawn = 'withdrawn';
    case Activated = 'activated';
    case Failed    = 'failed';
}
