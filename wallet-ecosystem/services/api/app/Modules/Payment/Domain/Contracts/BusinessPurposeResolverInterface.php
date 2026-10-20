<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use App\Modules\Payment\Domain\ValueObjects\PayableObligation;

interface BusinessPurposeResolverInterface
{
    /**
     * The purpose_type string this resolver handles (e.g., 'funeral_contribution').
     */
    public function supportedPurposeType(): string;

    /**
     * Resolve the payable obligation for a purpose reference.
     *
     * The Payment module calls this to get the authoritative amount, currency,
     * organisation, and payer. The mobile-supplied amount is NEVER used.
     *
     * @throws PurposeNotPayableException when purpose does not exist, is already paid, or is ineligible
     */
    public function resolve(
        string $purposeReference,
        string $actorId,
        string $correlationId,
    ): PayableObligation;
}
