<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum ReconciliationState: string
{
    case PENDING   = 'pending';
    case MATCHED   = 'matched';
    case UNMATCHED = 'unmatched'; // Reserved — no E1000 contract transitions to this value; deferred to E20
    case EXCEPTION = 'exception';
}
