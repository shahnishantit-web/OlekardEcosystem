<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Domain\Enums\PaymentDisputeCaseStatus;

final readonly class DisputeOutcomeRequest
{
    /**
     * @param PaymentDisputeCaseStatus::Won|PaymentDisputeCaseStatus::Lost|PaymentDisputeCaseStatus::ClosedReversed $outcome
     */
    public function __construct(
        public readonly PaymentDisputeCaseStatus $outcome,
        public readonly string                   $providerDecisionReference,
        public readonly int                      $chargebackFeeMinor = 0,
    ) {
    }
}
