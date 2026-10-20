<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Enums;

enum ConfigurationDomain: string
{
    case FinancialPolicy   = 'financial_policy';
    case OperationalPolicy = 'operational_policy';
    case Geographic        = 'geographic';

    public function label(): string
    {
        return match($this) {
            self::FinancialPolicy   => 'Financial Policy',
            self::OperationalPolicy => 'Operational Policy',
            self::Geographic        => 'Geographic',
        };
    }
}
