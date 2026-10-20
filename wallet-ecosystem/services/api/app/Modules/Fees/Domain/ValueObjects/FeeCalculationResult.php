<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain\ValueObjects;

final readonly class FeeCalculationResult
{
    public function __construct(
        public int     $feeAmountMinor,
        public ?string $feeRuleId,
        public ?string $feeType,
        public bool    $hasApplicableRule,
        public bool    $minFeeApplied,
        public bool    $maxFeeApplied,
    ) {}

    public static function zero(): self
    {
        return new self(
            feeAmountMinor:    0,
            feeRuleId:         null,
            feeType:           null,
            hasApplicableRule: false,
            minFeeApplied:     false,
            maxFeeApplied:     false,
        );
    }
}
