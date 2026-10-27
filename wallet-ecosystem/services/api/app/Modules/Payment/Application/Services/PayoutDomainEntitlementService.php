<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PayoutDomainEntitlementGatewayInterface;
use App\Modules\Payment\Domain\Exceptions\DomainEntitlementDeniedException;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;

final class PayoutDomainEntitlementService
{
    public function __construct(
        private readonly PayoutDomainEntitlementGatewayInterface $gateway,
        private readonly AuditServiceInterface                   $audit,
    ) {}

    public function assertEntitled(
        string $fundReferenceId,
        string $purposeType,
        string $organisationId,
        string $actorId,
    ): void {
        $entitled = $this->gateway->isEntitled($fundReferenceId, $purposeType, $organisationId);

        $this->audit->record(new AuditEventData(
            action:       'payment.payout.entitlement_checked',
            actorType:    'admin',
            resourceType: 'payout_fund',
            correlationId: $fundReferenceId,
            outcome:      $entitled ? 'success' : 'failure',
            actorId:      $actorId,
            resourceId:   $fundReferenceId,
            metadata:     ['purpose_type' => $purposeType, 'organisation_id' => $organisationId],
        ));

        if (! $entitled) {
            throw DomainEntitlementDeniedException::forFund($fundReferenceId, $purposeType);
        }
    }
}
