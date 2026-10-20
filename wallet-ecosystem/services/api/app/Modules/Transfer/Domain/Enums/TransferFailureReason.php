<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Enums;

enum TransferFailureReason: string
{
    case InsufficientBalance      = 'INSUFFICIENT_BALANCE';
    case DailyLimitExceeded       = 'DAILY_LIMIT_EXCEEDED';
    case RiskRejected             = 'RISK_REJECTED';
    case RecipientWalletSuspended = 'RECIPIENT_WALLET_SUSPENDED';
    case SystemError              = 'SYSTEM_ERROR';
}
