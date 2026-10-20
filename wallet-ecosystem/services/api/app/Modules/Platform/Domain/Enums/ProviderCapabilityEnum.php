<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum ProviderCapabilityEnum: string
{
    case InitiatePayment        = 'initiate_payment';
    case QueryBalance           = 'query_balance';
    case ReceiveWebhook         = 'receive_webhook';
    case ReversePriorPayment    = 'reverse_prior_payment';
    case DeliverOtp             = 'deliver_otp';
    case VerifyIdentity         = 'verify_identity';
    case ScreenSanctions        = 'screen_sanctions';
    case ScoreRisk              = 'score_risk';
    case GenerateUtilityToken   = 'generate_utility_token';
    case QueryTransactionStatus = 'query_transaction_status';
}
