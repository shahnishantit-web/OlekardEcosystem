<?php

declare(strict_types=1);

namespace App\Modules\Funding\Domain\Exceptions;

final class FeeChangedException extends \RuntimeException
{
    public function __construct(
        public readonly int $recalculatedFeeAmount,
        public readonly int $recalculatedNetAmount,
    ) {
        parent::__construct(
            "Fee has changed since initiation. New fee: {$recalculatedFeeAmount}. Please re-confirm."
        );
    }
}
