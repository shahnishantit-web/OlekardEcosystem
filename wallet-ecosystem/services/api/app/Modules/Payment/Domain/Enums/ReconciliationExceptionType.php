<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum ReconciliationExceptionType: string
{
    case MissingCallback      = 'missing_callback';
    case UnknownOutcome       = 'unknown_outcome';
    case AmountMismatch       = 'amount_mismatch';
    case DuplicateTransaction = 'duplicate_transaction';
    case OrphanProvider       = 'orphan_provider';
    case FailedRefund         = 'failed_refund';
    case UnmatchedLedger      = 'unmatched_ledger';
}
