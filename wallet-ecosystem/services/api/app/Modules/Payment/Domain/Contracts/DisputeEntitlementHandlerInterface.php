<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\ValueObjects\DisputeEntitlementResult;

/**
 * Opt-in domain hook for purpose-type-specific dispute entitlement coordination.
 * Bound under the service container key: payment.dispute_entitlement.{purpose_type}
 * If no handler is registered for a purpose type, entitlement_action = no_handler.
 */
interface DisputeEntitlementHandlerInterface
{
    public function supportedPurposeType(): string;

    public function handleDisputeEntitlement(
        string $caseId,
        string $purposeReference,
        string $correlationId,
    ): DisputeEntitlementResult;
}
