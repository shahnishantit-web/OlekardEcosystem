<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Enums;

enum ExceptionType: string
{
    case UnmatchedProvider = 'unmatched_provider';
    case UnmatchedBank     = 'unmatched_bank';
    case AmountMismatch    = 'amount_mismatch';
    case Duplicate         = 'duplicate';
}
