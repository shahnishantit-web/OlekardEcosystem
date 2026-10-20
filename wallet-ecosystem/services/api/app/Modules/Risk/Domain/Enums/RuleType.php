<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Enums;

enum RuleType: string
{
    case AmountLimit       = 'amount_limit';
    case VelocityCount     = 'velocity_count';
    case VelocityAmount    = 'velocity_amount';
    case DeviceScore       = 'device_score';
    case IpScore           = 'ip_score';
    case BeneficiaryScore  = 'beneficiary_score';
    case AuthFailureCount  = 'auth_failure_count';
}
