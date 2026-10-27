<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum ProviderAccountCapability: string
{
    case SupportsRefund  = 'supports_refund';
    case SupportsWebhook = 'supports_webhook';
    case SupportsStepUp  = 'supports_step_up';
    case SupportsQr      = 'supports_qr';
    case SupportsEscrow  = 'supports_escrow';

    /** @return string[] */
    public static function keys(): array
    {
        return array_column(self::cases(), 'value');
    }
}
