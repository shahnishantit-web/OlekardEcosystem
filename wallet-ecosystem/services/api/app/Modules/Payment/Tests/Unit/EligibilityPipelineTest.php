<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\EligibilityPipeline;
use App\Modules\Payment\Domain\Enums\EligibilityReason;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use PHPUnit\Framework\TestCase;

final class EligibilityPipelineTest extends TestCase
{
    private EligibilityPipeline $pipeline;

    protected function setUp(): void
    {
        $this->pipeline = new EligibilityPipeline();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function baseQuery(array $overrides = []): OptionsQuery
    {
        return new OptionsQuery(
            purposeType:      $overrides['purposeType']      ?? 'bill',
            purposeReference: $overrides['purposeReference'] ?? 'ref-1',
            currencyCode:     $overrides['currencyCode']     ?? 'BWP',
            amountMinor:      $overrides['amountMinor']      ?? 5000,
            organisationId:   $overrides['organisationId']   ?? 'org-001',
            actorId:          $overrides['actorId']          ?? 'actor-001',
            countryCode:      $overrides['countryCode']      ?? 'BW',
            moduleKey:        $overrides['moduleKey']        ?? 'wallet',
            requiredCapability: $overrides['requiredCapability'] ?? null,
        );
    }

    private function baseAccount(array $overrides = []): PaymentProviderAccountModel
    {
        $provider              = new PaymentProviderModel();
        $provider->is_enabled  = $overrides['providerEnabled']   ?? true;
        $provider->is_suspended = $overrides['providerSuspended'] ?? false;

        $account                         = new PaymentProviderAccountModel();
        $account->is_active              = $overrides['isActive']              ?? true;
        $account->enabled_organisations  = $overrides['enabledOrgs']           ?? [];
        $account->enabled_modules        = $overrides['enabledModules']        ?? [];
        $account->enabled_purpose_types  = $overrides['enabledPurposeTypes']   ?? [];
        $account->country_restrictions   = $overrides['countryRestrictions']   ?? [];
        $account->supported_currencies   = $overrides['currencies']            ?? ['BWP'];
        $account->min_amount_minor       = $overrides['minAmount']             ?? 100;
        $account->max_amount_minor       = $overrides['maxAmount']             ?? 10_000_000;
        $account->capability_flags       = $overrides['capabilityFlags']       ?? [];
        $account->setRelation('provider', $provider);

        return $account;
    }

    // ── Stage 1 — Platform ───────────────────────────────────────────────────

    public function test_provider_disabled_fails_stage_1(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['providerEnabled' => false]),
            $this->baseQuery(),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::ProviderDisabled, $result->reason);
    }

    public function test_provider_suspended_fails_stage_1(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['providerSuspended' => true]),
            $this->baseQuery(),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::ProviderSuspended, $result->reason);
    }

    public function test_account_inactive_fails_stage_1(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['isActive' => false]),
            $this->baseQuery(),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::AccountInactive, $result->reason);
    }

    // ── Stage 2 — Organisation ───────────────────────────────────────────────

    public function test_empty_enabled_orgs_allows_any_org(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['enabledOrgs' => []]),
            $this->baseQuery(['organisationId' => 'any-org']),
        );

        self::assertTrue($result->isEligible);
    }

    public function test_org_not_in_restricted_list_fails_stage_2(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['enabledOrgs' => ['org-allowed']]),
            $this->baseQuery(['organisationId' => 'org-other']),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::OrganisationNotEnabled, $result->reason);
    }

    // ── Stage 3 — Module ────────────────────────────────────────────────────

    public function test_module_not_in_restricted_list_fails_stage_3(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['enabledModules' => ['wallet']]),
            $this->baseQuery(['moduleKey' => 'lending']),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::ModuleNotEnabled, $result->reason);
    }

    // ── Stage 4 — Purpose Type ───────────────────────────────────────────────

    public function test_purpose_type_not_in_restricted_list_fails_stage_4(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['enabledPurposeTypes' => ['bill_payment']]),
            $this->baseQuery(['purposeType' => 'transfer']),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::PurposeTypeNotEnabled, $result->reason);
    }

    public function test_empty_enabled_purpose_types_allows_any_purpose(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['enabledPurposeTypes' => []]),
            $this->baseQuery(['purposeType' => 'any_purpose_type']),
        );

        self::assertTrue($result->isEligible);
    }

    // ── Stage 5 — Country ───────────────────────────────────────────────────

    public function test_country_in_deny_list_fails_stage_5(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['countryRestrictions' => ['ZW', 'ZM']]),
            $this->baseQuery(['countryCode' => 'ZW']),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::CountryRestricted, $result->reason);
    }

    public function test_empty_country_restrictions_allows_any_country(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['countryRestrictions' => []]),
            $this->baseQuery(['countryCode' => 'ZW']),
        );

        self::assertTrue($result->isEligible);
    }

    // ── Stage 6 — Currency ───────────────────────────────────────────────────

    public function test_unsupported_currency_fails_stage_6(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['currencies' => ['USD']]),
            $this->baseQuery(['currencyCode' => 'BWP']),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::CurrencyNotSupported, $result->reason);
    }

    // ── Stage 7 — Amount ────────────────────────────────────────────────────

    public function test_amount_below_minimum_fails_stage_7(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['minAmount' => 1000]),
            $this->baseQuery(['amountMinor' => 500]),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::AmountBelowMinimum, $result->reason);
    }

    public function test_amount_above_maximum_fails_stage_7(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['maxAmount' => 10_000]),
            $this->baseQuery(['amountMinor' => 15_000]),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::AmountAboveMaximum, $result->reason);
    }

    public function test_zero_amount_skips_stage_7(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['minAmount' => 500]),
            $this->baseQuery(['amountMinor' => 0]),
        );

        self::assertTrue($result->isEligible);
    }

    // ── Stage 8 — Capability ────────────────────────────────────────────────

    public function test_missing_required_capability_fails_stage_8(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['capabilityFlags' => ['supports_qr' => false]]),
            $this->baseQuery(['requiredCapability' => 'supports_qr']),
        );

        self::assertFalse($result->isEligible);
        self::assertSame(EligibilityReason::CapabilityNotSupported, $result->reason);
    }

    public function test_null_required_capability_skips_stage_8(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['capabilityFlags' => []]),
            $this->baseQuery(['requiredCapability' => null]),
        );

        self::assertTrue($result->isEligible);
    }

    public function test_present_required_capability_passes_stage_8(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(['capabilityFlags' => ['supports_qr' => true]]),
            $this->baseQuery(['requiredCapability' => 'supports_qr']),
        );

        self::assertTrue($result->isEligible);
    }

    // ── Short-circuit ────────────────────────────────────────────────────────

    public function test_stage_1_failure_does_not_evaluate_later_stages(): void
    {
        // Provider is disabled — even though currency is unsupported, reason should be PROVIDER_DISABLED
        $result = $this->pipeline->evaluate(
            $this->baseAccount([
                'providerEnabled' => false,
                'currencies'      => ['USD'], // would also fail stage 6
            ]),
            $this->baseQuery(['currencyCode' => 'BWP']),
        );

        self::assertSame(EligibilityReason::ProviderDisabled, $result->reason);
    }

    // ── Full eligible path ───────────────────────────────────────────────────

    public function test_all_stages_pass_returns_eligible(): void
    {
        $result = $this->pipeline->evaluate(
            $this->baseAccount(),
            $this->baseQuery(),
        );

        self::assertTrue($result->isEligible);
        self::assertNull($result->reason);
    }
}
