<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum DisputeCaseType: string
{
    case Reversal   = 'reversal';
    case Dispute    = 'dispute';
    case Chargeback = 'chargeback';
}
