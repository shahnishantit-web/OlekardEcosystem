<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Domain\Enums\EligibilityReason;

final readonly class EligibilityResult
{
    private function __construct(
        public bool              $isEligible,
        public ?EligibilityReason $reason,
    ) {
    }

    public static function eligible(): self
    {
        return new self(isEligible: true, reason: null);
    }

    public static function ineligible(EligibilityReason $reason): self
    {
        return new self(isEligible: false, reason: $reason);
    }
}
