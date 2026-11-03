<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Events;

use App\Modules\Events\Domain\ValueObjects\EventPricingBreakdown;
use PHPUnit\Framework\TestCase;

/** Synthetic validator fixtures only; these values are not approved production pricing. */
final class EventPricingBreakdownTest extends TestCase
{
    public function test_approved_pricing_requires_versioned_organiser_fee_and_tax_snapshots(): void
    {
        $pricing = $this->validSyntheticProductionShape();

        $pricing->assertEnvironmentEligible();

        self::assertSame(100, $pricing->toArray()['organiser_platform_fee_minor']);
        self::assertSame(1075, $pricing->totalMinor, 'The organiser fee is excluded from the customer payable total.');
        self::assertSame('organisation', $pricing->toArray()['organiser_fee_schedule_snapshot']['scope']['type']);
    }

    public function test_missing_fee_schedule_or_tax_matrix_fails_closed(): void
    {
        $base = $this->validSyntheticProductionShape();
        $missingFeeSchedule = $this->copy($base, organiserFeeScheduleSnapshot: null);
        $missingTaxMatrix = $this->copy($base, taxPolicySnapshot: null);

        try {
            $missingFeeSchedule->assertEnvironmentEligible();
            self::fail('Missing organiser fee schedule must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('explicit organiser fee schedule', $exception->getMessage());
        }
        try {
            $missingTaxMatrix->assertEnvironmentEligible();
            self::fail('Missing qualified tax matrix must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('explicit organiser fee schedule', $exception->getMessage());
        }
    }

    public function test_unapproved_policy_or_unverified_seller_fails_closed(): void
    {
        foreach ([
            $this->copy($this->validSyntheticProductionShape(), policyApproved: false),
            $this->copy($this->validSyntheticProductionShape(), sellerVerified: false),
        ] as $pricing) {
            try {
                $pricing->assertEnvironmentEligible();
                self::fail('Unapproved pricing or an unverified seller must be rejected.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('Approved Finance pricing policy and verified seller evidence', $exception->getMessage());
            }
        }
    }

    public function test_fee_schedule_currency_mismatch_fails_closed(): void
    {
        $schedule = $this->feeSchedule();
        $schedule['currency_code'] = 'USD';
        $pricing = $this->validSyntheticProductionShape(organiserFeeScheduleSnapshot: $schedule);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('approved currency, version or scope');
        $pricing->assertEnvironmentEligible();
    }

    public function test_customer_payable_booking_fee_is_rejected_without_separate_policy_approval(): void
    {
        $pricing = $this->validSyntheticProductionShape(feesMinor: 25, totalMinor: 1100,
            feeComponents: [['code' => 'customer_booking_fee', 'amount_minor' => 25, 'policy_version' => 'synthetic-fees-v1']]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Customer-payable booking fees are unavailable');
        $pricing->assertEnvironmentEligible();
    }

    public function test_binary_floating_point_is_rejected_from_tax_snapshot(): void
    {
        $tax = $this->taxSnapshot();
        $tax['rates'] = [['rate' => 0.075]];
        $pricing = $this->validSyntheticProductionShape(taxPolicySnapshot: $tax);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('without binary floating point');
        $pricing->assertEnvironmentEligible();
    }

    private function validSyntheticProductionShape(
        int $feesMinor = 0,
        int $totalMinor = 1075,
        array $feeComponents = [],
        ?array $organiserFeeScheduleSnapshot = null,
        ?array $taxPolicySnapshot = null,
    ): EventPricingBreakdown {
        return new EventPricingBreakdown(
            subtotalMinor: 1000,
            feesMinor: $feesMinor,
            taxesMinor: 75,
            totalMinor: $totalMinor,
            currencyCode: 'BWP',
            currencyPrecision: 2,
            sellerMerchantReference: 'synthetic-verified-seller',
            feePolicyVersion: 'synthetic-fees-v1',
            taxPolicyVersion: 'synthetic-tax-v1',
            feeComponents: $feeComponents,
            taxComponents: [['code' => 'synthetic-tax', 'amount_minor' => 75, 'policy_version' => 'synthetic-tax-v1']],
            policyExpiresAt: new \DateTimeImmutable('+5 minutes'),
            policyEvidenceReference: 'synthetic-approval-fixture',
            policyApproved: true,
            sellerVerified: true,
            testOnly: false,
            organiserPlatformFeeMinor: 100,
            organiserFeeScheduleSnapshot: $organiserFeeScheduleSnapshot ?? $this->feeSchedule(),
            taxPolicySnapshot: $taxPolicySnapshot ?? $this->taxSnapshot(),
        );
    }

    private function feeSchedule(): array
    {
        return [
            'version' => 'synthetic-fees-v1',
            'approval_evidence_reference' => 'synthetic-fee-approval-fixture',
            'effective_from' => '2026-01-01T00:00:00+00:00',
            'effective_until' => null,
            'currency_code' => 'BWP',
            'calculation_basis' => 'synthetic-approved-basis-id',
            'scope' => ['type' => 'organisation', 'id' => 'synthetic-organisation'],
            'components' => [['type' => 'fixed', 'value' => '1.00', 'amount_minor' => 100, 'payer' => 'organiser']],
        ];
    }

    private function taxSnapshot(): array
    {
        return [
            'version' => 'synthetic-tax-v1',
            'approval_evidence_reference' => 'synthetic-tax-approval-fixture',
            'effective_from' => '2026-01-01T00:00:00+00:00',
            'effective_until' => null,
            'jurisdiction' => 'synthetic-jurisdiction',
            'ticket_classification' => 'synthetic-classification',
            'ticket_treatment' => 'synthetic-treatment',
            'service_fee_treatment' => 'synthetic-treatment',
            'price_presentation' => 'inclusive',
            'rates' => [['rate' => '0.075']],
            'exemptions' => [],
            'taxable_bases' => ['synthetic-base'],
            'calculation_order' => ['synthetic-order'],
            'currency_precision' => 2,
            'rounding_scope' => 'synthetic-line-scope',
            'rounding_method' => 'synthetic-method',
            'disclosures' => ['synthetic-disclosure'],
            'invoice_responsibility' => 'synthetic-responsible-party',
        ];
    }

    private function copy(
        EventPricingBreakdown $source,
        ?array $organiserFeeScheduleSnapshot = null,
        ?array $taxPolicySnapshot = null,
        ?bool $policyApproved = null,
        ?bool $sellerVerified = null,
    ): EventPricingBreakdown {
        return new EventPricingBreakdown(
            $source->subtotalMinor, $source->feesMinor, $source->taxesMinor, $source->totalMinor,
            $source->currencyCode, $source->currencyPrecision, $source->sellerMerchantReference,
            $source->feePolicyVersion, $source->taxPolicyVersion, $source->feeComponents, $source->taxComponents,
            $source->policyExpiresAt, $source->policyEvidenceReference, $policyApproved ?? $source->policyApproved,
            $sellerVerified ?? $source->sellerVerified, $source->testOnly, $source->organiserPlatformFeeMinor,
            $organiserFeeScheduleSnapshot, $taxPolicySnapshot,
        );
    }
}
