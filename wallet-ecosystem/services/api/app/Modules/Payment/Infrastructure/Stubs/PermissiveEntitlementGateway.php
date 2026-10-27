<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Stubs;

use App\Modules\Payment\Domain\Contracts\PayoutDomainEntitlementGatewayInterface;
use Illuminate\Support\Facades\Log;

/**
 * Default entitlement gateway — permits all requests but logs a warning.
 * Domain modules MUST replace this binding in their own ServiceProvider
 * to enforce real business lifecycle checks (FR-002).
 */
final class PermissiveEntitlementGateway implements PayoutDomainEntitlementGatewayInterface
{
    public function isEntitled(string $fundReferenceId, string $purposeType, string $organisationId): bool
    {
        Log::warning('PayoutDomainEntitlementGateway: using permissive default — no domain module has registered a real entitlement check.', [
            'fund_reference_id' => $fundReferenceId,
            'purpose_type'      => $purposeType,
            'organisation_id'   => $organisationId,
        ]);

        return true;
    }
}
