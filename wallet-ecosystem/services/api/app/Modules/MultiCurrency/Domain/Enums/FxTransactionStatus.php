<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Domain\Enums;

enum FxTransactionStatus: string
{
    case Completed = 'completed';
    case Failed    = 'failed';
}
