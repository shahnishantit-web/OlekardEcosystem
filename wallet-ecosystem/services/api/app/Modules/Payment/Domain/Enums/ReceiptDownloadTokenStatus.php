<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum ReceiptDownloadTokenStatus: string
{
    case Pending = 'pending';
    case Used    = 'used';
    case Expired = 'expired';

    public function isDepleted(): bool
    {
        return $this === self::Used || $this === self::Expired;
    }
}
