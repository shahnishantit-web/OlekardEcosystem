<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Enums;

enum SettlementStatus: string
{
    case Draft           = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Executing       = 'executing';
    case Executed        = 'executed';
    case Rejected        = 'rejected';
    case Failed          = 'failed';
}
