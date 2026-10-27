<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Exceptions;

use DomainException;

final class DomainEntitlementDeniedException extends DomainException
{
    public static function forFund(string $fundReferenceId, string $purposeType): self
    {
        return new self(
            sprintf(
                'Domain entitlement not granted for fund "%s" (purpose: %s) — business lifecycle must be confirmed complete before disbursement.',
                $fundReferenceId,
                $purposeType,
            )
        );
    }
}
