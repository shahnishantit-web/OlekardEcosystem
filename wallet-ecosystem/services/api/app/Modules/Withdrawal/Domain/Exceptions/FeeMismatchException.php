<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Domain\Exceptions;

use RuntimeException;

final class FeeMismatchException extends RuntimeException
{
    public function __construct(
        public readonly int $updatedFeeAmount,
        public readonly int $updatedNetPayoutAmount,
    ) {
        parent::__construct(
            "Fee has changed since initiation. Current fee: {$updatedFeeAmount}, net payout: {$updatedNetPayoutAmount}"
        );
    }
}
