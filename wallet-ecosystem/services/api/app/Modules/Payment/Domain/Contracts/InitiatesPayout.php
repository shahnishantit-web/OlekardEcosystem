<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\ValueObjects\PayoutInstruction;
use App\Modules\Payment\Domain\ValueObjects\PayoutResult;

/** Optional capability; incoming payment support never implies payout support. */
interface InitiatesPayout
{
    public function initiatePayout(PayoutInstruction $instruction): PayoutResult;
}
