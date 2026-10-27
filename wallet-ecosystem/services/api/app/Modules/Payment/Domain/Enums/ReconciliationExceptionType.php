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
    // P06: authoritative-evidence guards
    case ContradictoryEvidence = 'contradictory_evidence';
    case ExcessCapture         = 'excess_capture';
    // P07: genuine provider double-charge under a distinct provider reference
    case DuplicateCapture      = 'duplicate_capture';
    // P08: repair job exhausted max retries without posting the ledger journal
    case AccountingFailure     = 'accounting_failure';
    // P15: provider does not support refunds for this payment method
    case UnsupportedRefundMethod = 'unsupported_refund_method';
    // P15: provider permanently declined the refund after exhausting all retries
    case DefinitiveRefundFailure = 'definitive_refund_failure';
    // P16: provider dispute/chargeback event references an unknown payment attempt
    case UnmatchedDisputeEvent = 'unmatched_dispute_event';
    // P17: fee line in statement with no matching capture on platform
    case OrphanFee = 'orphan_fee';
    // P17: clearing record exists but bank settlement batch has not arrived within ageing window
    case PartialSettlement = 'partial_settlement';
}
