<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Enums;

enum AdminActionType: string
{
    case SuspendUser             = 'suspend_user';
    case ReactivateUser          = 'reactivate_user';
    case ResetAuth               = 'reset_auth';
    case TerminateSession        = 'terminate_session';
    case FreezeWallet            = 'freeze_wallet';
    case UnfreezeWallet          = 'unfreeze_wallet';
    case WalletAdjustmentCreated = 'wallet_adjustment_created';
    case WalletAdjustmentApproved = 'wallet_adjustment_approved';
    case WalletAdjustmentRejected = 'wallet_adjustment_rejected';
    case InvestigateTransaction  = 'investigate_transaction';
    case TransactionReversed     = 'transaction_reversed';
    case TransactionRefunded     = 'transaction_refunded';
    case TransactionsExported    = 'transactions_exported';
}
