<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Stubs;

use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
use App\Modules\Payment\Domain\Exceptions\ProductionBlockedException;
use App\Modules\Payment\Domain\ValueObjects\EnrichedPayableObligation;

/**
 * Development-only stub resolver. NEVER available in production or staging.
 * Exercises the full quote/validation flow with a fixed obligation so developers
 * can test end-to-end payment flows without a real domain module wired up.
 *
 * @dev-only
 */
final class DevPurposeStubResolver implements BusinessPurposeResolverInterface
{
    public const TEST_ORG_ID = 'org-dev-stub-00000000-0000-0000-0000-000000000001';

    public function __construct()
    {
        if (app()->environment('production', 'staging')) {
            throw new ProductionBlockedException('dev_purpose_stub_resolver');
        }
    }

    public function supportedPurposeType(): string
    {
        return 'dev_test_purpose';
    }

    public function resolve(
        string $purposeReference,
        string $actorId,
        string $correlationId,
    ): EnrichedPayableObligation {
        return new EnrichedPayableObligation(
            amountMinor:             10000,
            currencyCode:            'BWP',
            organisationId:          self::TEST_ORG_ID,
            payerActorId:            $actorId,
            expiresAt:               null,
            holdReference:           null,
            beneficiaryAllocations:  [],
            metadata:                ['stub' => true, 'purpose_reference' => $purposeReference],
        );
    }
}
