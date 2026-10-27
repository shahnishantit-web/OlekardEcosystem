<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

enum EligibilityReason: string
{
    case ProviderDisabled      = 'PROVIDER_DISABLED';
    case ProviderSuspended     = 'PROVIDER_SUSPENDED';
    case AccountInactive       = 'ACCOUNT_INACTIVE';
    case OrganisationNotEnabled = 'ORGANISATION_NOT_ENABLED';
    case ModuleNotEnabled      = 'MODULE_NOT_ENABLED';
    case PurposeTypeNotEnabled = 'PURPOSE_TYPE_NOT_ENABLED';
    case CountryRestricted     = 'COUNTRY_RESTRICTED';
    case CurrencyNotSupported  = 'CURRENCY_NOT_SUPPORTED';
    case AmountBelowMinimum    = 'AMOUNT_BELOW_MINIMUM';
    case AmountAboveMaximum    = 'AMOUNT_ABOVE_MAXIMUM';
    case CapabilityNotSupported = 'CAPABILITY_NOT_SUPPORTED';
    // T078: P12 — account suspended or retired blocks new payment attempts
    case AccountNotEligibleForNewAttempts = 'ACCOUNT_NOT_ELIGIBLE_FOR_NEW_ATTEMPTS';
}
