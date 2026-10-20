<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum ProviderCapabilityEnum: string
{
    case InitiatePayment  = 'initiate_payment';
    case QueryPayment     = 'query_payment';
    case VerifyCallback   = 'verify_callback';
    case FullRefund       = 'full_refund';
    case PartialRefund    = 'partial_refund';
    case CancelPayment    = 'cancel_payment';
    case AuthoriseCapture = 'authorise_capture';
    case Payout           = 'payout';
}
