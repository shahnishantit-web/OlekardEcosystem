<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

/**
 * Domain modules implement and bind this interface in their own ServiceProvider
 * to confirm their fund lifecycle is complete and disbursement is authorised (FR-002).
 *
 * The default binding in PaymentServiceProvider is PermissiveEntitlementGateway
 * (always returns true with a warning). Replace this binding in your domain module's
 * ServiceProvider to enforce real business lifecycle checks.
 */
interface PayoutDomainEntitlementGatewayInterface
{
    public function isEntitled(string $fundReferenceId, string $purposeType, string $organisationId): bool;
}
