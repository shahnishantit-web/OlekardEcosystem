<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure;

use App\Modules\Events\Domain\Contracts\EventPricingContract;
use App\Modules\Events\Domain\ValueObjects\EventPricingBreakdown;
use App\Modules\Events\Domain\ValueObjects\EventPricingRequest;

/** Zero-fee local fixture pricing; protected by an explicit local-only opt-in. */
final class LocalDemoEventPricingContract implements EventPricingContract
{
    public function quote(EventPricingRequest $request): EventPricingBreakdown
    {
        abort_unless(app()->environment('local') && (config('events.local_demo_pricing_enabled') === true
            || config('events.poc_enabled') === true),
            503, 'Local demo event pricing is disabled.');

        $subtotal = $request->subtotalMinor();

        return new EventPricingBreakdown(
            subtotalMinor: $subtotal,
            feesMinor: 0,
            taxesMinor: 0,
            totalMinor: $subtotal,
            currencyCode: $request->currencyCode,
            currencyPrecision: $request->currencyPrecision,
            sellerMerchantReference: 'LOCAL-DEMO-'.$request->organisationId,
            feePolicyVersion: 'local-demo-zero-fee-v1',
            taxPolicyVersion: 'local-demo-no-tax-v1',
            feeComponents: [],
            taxComponents: [],
            policyExpiresAt: now()->addMinutes(10)->toImmutable(),
            policyEvidenceReference: 'local-demo://paid-event-pricing/'.$request->eventId,
            policyApproved: false,
            sellerVerified: false,
            testOnly: true,
        );
    }
}
