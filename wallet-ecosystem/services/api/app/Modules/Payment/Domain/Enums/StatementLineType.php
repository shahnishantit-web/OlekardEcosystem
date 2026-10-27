<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum StatementLineType: string
{
    case Capture          = 'capture';
    case Fee              = 'fee';
    case Refund           = 'refund';
    case Reversal         = 'reversal';
    case Chargeback       = 'chargeback';
    case InternalTransfer = 'internal_transfer';
    case Adjustment       = 'adjustment';

    public function createsClearingRecord(): bool
    {
        return $this === self::Capture;
    }

    public function isInternal(): bool
    {
        return $this === self::InternalTransfer;
    }
}
