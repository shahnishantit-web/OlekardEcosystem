<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain\Enums;

enum IntegrationProviderType: string
{
    case Otp         = 'otp';
    case Sms         = 'sms';
    case Email       = 'email';
    case Kyc         = 'kyc';
    case Payment     = 'payment';
    case Bank        = 'bank';
    case MobileMoney = 'mobile_money';
}
