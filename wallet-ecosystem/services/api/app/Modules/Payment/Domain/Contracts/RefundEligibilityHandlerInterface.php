<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Exceptions\RefundEligibilityDeniedException;

/**
 * Opt-in domain hook for purpose-type-specific refund eligibility.
 * Bound under the service container key: payment.refund_eligibility.{purpose_type}
 * If no handler is registered for a purpose type, the refund is permitted to proceed.
 */
interface RefundEligibilityHandlerInterface
{
    /**
     * Assert the refund is eligible for the given intent and actor.
     *
     * @throws RefundEligibilityDeniedException when the domain rules deny this refund
     */
    public function assertEligible(
        string $paymentIntentId,
        string $purposeType,
        int    $requestedAmountMinor,
        string $currencyCode,
        string $actorId,
    ): void;
}
