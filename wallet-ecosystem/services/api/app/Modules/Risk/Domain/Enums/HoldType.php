<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Enums;

enum HoldType: string
{
    case RiskFreeze       = 'risk_freeze';
    case ComplianceHold   = 'compliance_hold';
    case AmlInvestigation = 'aml_investigation';
}
