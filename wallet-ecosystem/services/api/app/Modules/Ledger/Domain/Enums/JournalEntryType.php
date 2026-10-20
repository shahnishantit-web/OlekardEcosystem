<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain\Enums;

enum JournalEntryType: string
{
    case Debit      = 'debit';
    case Credit     = 'credit';
    case Fee        = 'fee';
    case Revenue    = 'revenue';
    case Commission = 'commission';
    /** Internal only — created by LedgerReverseService; not accepted via postEntry(). */
    case Reversal   = 'reversal';
    /** Posted by ExecuteSettlementJob (E20) for provider/bank settlement payments. */
    case Settlement = 'settlement';
}
