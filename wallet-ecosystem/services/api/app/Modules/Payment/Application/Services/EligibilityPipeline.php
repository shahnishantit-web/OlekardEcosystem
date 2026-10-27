<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Enums\EligibilityReason;
use App\Modules\Payment\Domain\ValueObjects\EligibilityResult;
use App\Modules\Payment\Domain\ValueObjects\OptionsQuery;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderAccountModel;

/**
 * Ordered 8-stage eligibility pipeline for payment provider options.
 *
 * A provider account failing any stage is excluded; later stages are not evaluated.
 * This service is stateless and safe to use as a singleton.
 */
final class EligibilityPipeline
{
    public function evaluate(PaymentProviderAccountModel $account, OptionsQuery $query): EligibilityResult
    {
        // Stage 1: Platform restrictions
        $provider = $account->provider;
        if ($provider === null || ! $provider->is_enabled) {
            return EligibilityResult::ineligible(EligibilityReason::ProviderDisabled);
        }
        if ($provider->is_suspended) {
            return EligibilityResult::ineligible(EligibilityReason::ProviderSuspended);
        }
        if (! $account->is_active) {
            return EligibilityResult::ineligible(EligibilityReason::AccountInactive);
        }

        // Stage 1b: P12 lifecycle eligibility — suspended/retired accounts block new attempts
        if (! $account->isEligibleForNewAttempts()) {
            return EligibilityResult::ineligible(EligibilityReason::AccountNotEligibleForNewAttempts);
        }

        // Stage 2: Organisation eligibility (empty = unrestricted)
        $enabledOrgs = $account->enabled_organisations ?? [];
        if (! empty($enabledOrgs) && ! in_array($query->organisationId, $enabledOrgs, true)) {
            return EligibilityResult::ineligible(EligibilityReason::OrganisationNotEnabled);
        }

        // Stage 3: Module eligibility (empty = unrestricted)
        $enabledModules = $account->enabled_modules ?? [];
        if ($query->moduleKey !== '' && ! empty($enabledModules) && ! in_array($query->moduleKey, $enabledModules, true)) {
            return EligibilityResult::ineligible(EligibilityReason::ModuleNotEnabled);
        }

        // Stage 4: Purpose-type eligibility (empty = unrestricted)
        $enabledPurposeTypes = $account->enabled_purpose_types ?? [];
        if (! empty($enabledPurposeTypes) && ! in_array($query->purposeType, $enabledPurposeTypes, true)) {
            return EligibilityResult::ineligible(EligibilityReason::PurposeTypeNotEnabled);
        }

        // Stage 5: Country eligibility (non-empty = deny-list)
        $countryRestrictions = $account->country_restrictions ?? [];
        if (! empty($countryRestrictions) && $query->countryCode !== '' && in_array($query->countryCode, $countryRestrictions, true)) {
            return EligibilityResult::ineligible(EligibilityReason::CountryRestricted);
        }

        // Stage 6: Currency eligibility
        $supportedCurrencies = $account->supported_currencies ?? [];
        if (! in_array($query->currencyCode, $supportedCurrencies, true)) {
            return EligibilityResult::ineligible(EligibilityReason::CurrencyNotSupported);
        }

        // Stage 7: Amount range eligibility (skip when amount = 0, treated as unspecified)
        if ($query->amountMinor > 0) {
            if ($query->amountMinor < $account->min_amount_minor) {
                return EligibilityResult::ineligible(EligibilityReason::AmountBelowMinimum);
            }
            if ($query->amountMinor > $account->max_amount_minor) {
                return EligibilityResult::ineligible(EligibilityReason::AmountAboveMaximum);
            }
        }

        // Stage 8: Capability eligibility (optional — only when requiredCapability is specified)
        if ($query->requiredCapability !== null) {
            $capabilityFlags = $account->capability_flags ?? [];
            if (empty($capabilityFlags[$query->requiredCapability])) {
                return EligibilityResult::ineligible(EligibilityReason::CapabilityNotSupported);
            }
        }

        return EligibilityResult::eligible();
    }
}
