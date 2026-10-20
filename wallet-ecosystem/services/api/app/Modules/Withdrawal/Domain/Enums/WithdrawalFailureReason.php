<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Enums;

enum WithdrawalFailureReason: string
{
    case ProviderDeclined  = 'provider_declined';
    case ProcessingTimeout = 'processing_timeout';
    case JobDispatchFailed = 'job_dispatch_failed';
    case AmountMismatch    = 'amount_mismatch';
    case RiskRejected      = 'risk_rejected';
    case AdminForceFailed  = 'admin_force_failed';
    case SystemError       = 'system_error';
}
