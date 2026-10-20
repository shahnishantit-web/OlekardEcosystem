<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests;

use App\Modules\Payment\Domain\Contracts\BusinessPurposeResolverInterface;
use App\Modules\Payment\Domain\ValueObjects\PayableObligation;

/**
 * T147: Stub BusinessPurposeResolver for integration tests.
 *
 * Returns a fixed PayableObligation so tests can exercise the full
 * createPaymentIntent flow without a real business module.
 *
 * ONLY available in test/local environments — guarded by constructor.
 */
final class StubPurposeResolver implements BusinessPurposeResolverInterface
{
    public const PURPOSE_TYPE = 'stub_payment';

    public const TEST_ORG_ID   = 'test-org-00000000-0000-0000-0000-000000000001';
    public const TEST_ACTOR_ID = 'test-actor-00000000-0000-0000-0000-000000000001';

    public function __construct()
    {
        $env = app()->environment();
        if ($env === 'production' || $env === 'staging') {
            throw new \LogicException('StubPurposeResolver must not be used in production or staging.');
        }
    }

    public function supportedPurposeType(): string
    {
        return self::PURPOSE_TYPE;
    }

    public function resolve(
        string $purposeReference,
        string $actorId,
        string $correlationId,
    ): PayableObligation {
        return new PayableObligation(
            amountMinor:    10000,
            currencyCode:   'BWP',
            organisationId: self::TEST_ORG_ID,
            payerActorId:   $actorId !== '' ? $actorId : self::TEST_ACTOR_ID,
            metadata:       ['stub' => true, 'purpose_reference' => $purposeReference],
        );
    }
}
