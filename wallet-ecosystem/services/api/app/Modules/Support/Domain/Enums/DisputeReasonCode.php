<?php

declare(strict_types=1);

namespace App\Modules\Support\Domain\Enums;

enum DisputeReasonCode: string
{
    case UnauthorizedTransaction  = 'unauthorized_transaction';
    case IncorrectAmount          = 'incorrect_amount';
    case TransactionNotReceived   = 'transaction_not_received';
    case DuplicateTransaction     = 'duplicate_transaction';
    case ServiceNotDelivered      = 'service_not_delivered';
    case TechnicalError           = 'technical_error';
    case Other                    = 'other';
}
