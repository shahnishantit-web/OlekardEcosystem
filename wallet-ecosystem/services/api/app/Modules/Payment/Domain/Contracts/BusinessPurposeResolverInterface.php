<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contracts;

use App\Modules\Payment\Domain\Exceptions\PurposeNotPayableException;
use App\Modules\Payment\Domain\ValueObjects\EnrichedPayableObligation;

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
     * organisation, payer, expiry, hold reference, and beneficiary allocations.
     * The mobile-supplied amount is NEVER used.
     *
     * Implementations MUST return EnrichedPayableObligation with:
     *   - amountMinor, currencyCode, organisationId, payerActorId (required)
     *   - expiresAt: null means no expiry; non-null is validated server-side
     *   - holdReference: null for P04; activation handled by P05+
     *   - beneficiaryAllocations: [] for unknown; content schema AWAITING_DOCUMENT:FD02
     *
     * @throws PurposeNotPayableException when purpose does not exist, is already paid, or is ineligible
     */
    public function resolve(
        string $purposeReference,
        string $actorId,
        string $correlationId,
    ): EnrichedPayableObligation;
}
