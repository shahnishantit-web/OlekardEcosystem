<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Domain\Exceptions;

final class FeeMismatchException extends \RuntimeException
{
    public function __construct(
        public readonly int $newFeeAmount,
        public readonly int $newNetAmount,
        public readonly int $newFeeRateBps,
    ) {
        parent::__construct(
            "Fee rate has changed. New fee: {$newFeeAmount}, net: {$newNetAmount}, rate: {$newFeeRateBps} bps."
        );
    }
}
