<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum BankCheckoutFlow: string
{
    case Redirect       = 'redirect';
    case HostedCheckout = 'hosted_checkout';
    case Sdk            = 'sdk';
    case AppSwitch      = 'app_switch';
}
