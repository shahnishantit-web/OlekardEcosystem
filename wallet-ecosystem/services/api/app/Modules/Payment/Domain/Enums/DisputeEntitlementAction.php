<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum DisputeEntitlementAction: string
{
    case BlockUnused              = 'blocked';
    case ConsumedEvidenceRecorded = 'consumed';
    case PostDrawLiability        = 'post_draw_liability';
    case NoHandler                = 'no_handler';
    case CoordinationFailed       = 'coordination_failed';
    case Pending                  = 'pending';
}
