<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Enums;

enum FundingFailureReason: string
{
    case ProviderDeclined    = 'provider_declined';
    case AmountMismatch      = 'amount_mismatch';
    case ProcessingTimeout   = 'processing_timeout';
    case BankReferenceExpired = 'bank_reference_expired';
    case WalletSuspended     = 'wallet_suspended';
    case FloatInsufficient   = 'float_insufficient';
    case JobDispatchFailed   = 'job_dispatch_failed';
}
