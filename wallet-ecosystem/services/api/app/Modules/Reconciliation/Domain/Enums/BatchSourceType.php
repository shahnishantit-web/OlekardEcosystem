<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Enums;

enum BatchSourceType: string
{
    case ProviderFile     = 'provider_file';
    case ProviderApi      = 'provider_api';
    case BankStatement    = 'bank_statement';
}
