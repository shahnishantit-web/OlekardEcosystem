<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum DisputeAccountingStatus: string
{
    case Pending                    = 'pending';
    case CorrectingJournalPosted    = 'correcting_journal_posted';
    case FeePosted                  = 'fee_posted';
    case HoldReleased               = 'hold_released';
    case ReceivableRecorded         = 'receivable_recorded';
    case PendingChargebackFeePolicy = 'pending_chargeback_fee_policy';
    // Zero-net lost: prior refunds fully covered the disputed amount; no chargeback journal needed
    case NoJournalRequired          = 'no_journal_required';
}
