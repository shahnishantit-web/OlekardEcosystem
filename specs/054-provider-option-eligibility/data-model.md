# Data Model: P05 — Provider Registry and Option Eligibility

## Modified Tables

### `payment.payment_provider_accounts` (ADD COLUMNS)

| Column | Type | Default | Nullable | Description |
|--------|------|---------|----------|-------------|
| `config_version` | INTEGER | 1 | NO | Monotonically increasing version counter; incremented on every approved or direct config change |
| `enabled_purpose_types` | JSONB | `'[]'` | NO | Allowed purpose types; empty = unrestricted |
| `display_description` | VARCHAR(255) | NULL | YES | Human-readable description for option UI |
| `display_icon_key` | VARCHAR(128) | NULL | YES | Design-system token key (not a URL) |
| `display_badge` | VARCHAR(64) | NULL | YES | Optional badge label e.g. `RECOMMENDED`, `NEW` |

### `payment.payment_attempts` (ADD COLUMN)

| Column | Type | Default | Nullable | Description |
|--------|------|---------|----------|-------------|
| `provider_account_version` | INTEGER | NULL | YES | `config_version` of the account at attempt initiation time; NULL for pre-P05 attempts |

### `payment.payment_config_change_drafts` (ADD COLUMN)

| Column | Type | Default | Nullable | Description |
|--------|------|---------|----------|-------------|
| `account_config_version_at_draft_time` | INTEGER | NULL | YES | `config_version` when draft was created; used for optimistic conflict detection at approval |

## New Domain Objects (no DB table)

### `EligibilityReason` enum (PHP, backed by string)

| Case | Value | Stage |
|------|-------|-------|
| `ProviderDisabled` | `PROVIDER_DISABLED` | Stage 1 |
| `ProviderSuspended` | `PROVIDER_SUSPENDED` | Stage 1 |
| `AccountInactive` | `ACCOUNT_INACTIVE` | Stage 1 |
| `OrganisationNotEnabled` | `ORGANISATION_NOT_ENABLED` | Stage 2 |
| `ModuleNotEnabled` | `MODULE_NOT_ENABLED` | Stage 3 |
| `PurposeTypeNotEnabled` | `PURPOSE_TYPE_NOT_ENABLED` | Stage 4 |
| `CountryRestricted` | `COUNTRY_RESTRICTED` | Stage 5 |
| `CurrencyNotSupported` | `CURRENCY_NOT_SUPPORTED` | Stage 6 |
| `AmountBelowMinimum` | `AMOUNT_BELOW_MINIMUM` | Stage 7 |
| `AmountAboveMaximum` | `AMOUNT_ABOVE_MAXIMUM` | Stage 7 |
| `CapabilityNotSupported` | `CAPABILITY_NOT_SUPPORTED` | Stage 8 |

### `ProviderAccountCapability` enum (PHP, backed by string)

Values: `supports_refund`, `supports_webhook`, `supports_step_up`, `supports_qr`, `supports_escrow`

### `PaymentInstrumentType` enum (PHP, backed by string)

Values: `wallet_transfer`, `bank_transfer`, `card_payment`, `qr_payment`, `mobile_money`

### `EligibilityResult` value object

```
EligibilityResult {
    isEligible: bool
    reason: ?EligibilityReason   // null when isEligible = true
}
```

## Eligibility Pipeline — Stage Flow

```
OptionsQuery + PaymentProviderAccountModel (with loaded provider)
    │
    ├─ Stage 1: platform
    │   is_enabled=true AND is_suspended=false AND is_active=true
    │   → PROVIDER_DISABLED / PROVIDER_SUSPENDED / ACCOUNT_INACTIVE
    │
    ├─ Stage 2: organisation
    │   enabled_organisations empty OR contains organisationId
    │   → ORGANISATION_NOT_ENABLED
    │
    ├─ Stage 3: module
    │   enabled_modules empty OR contains moduleKey
    │   → MODULE_NOT_ENABLED
    │
    ├─ Stage 4: purpose type
    │   enabled_purpose_types empty OR contains purposeType
    │   → PURPOSE_TYPE_NOT_ENABLED
    │
    ├─ Stage 5: country
    │   country_restrictions empty OR countryCode not in list
    │   → COUNTRY_RESTRICTED
    │
    ├─ Stage 6: currency
    │   supported_currencies contains currencyCode
    │   → CURRENCY_NOT_SUPPORTED
    │
    ├─ Stage 7: amount
    │   amountMinor >= min_amount_minor AND <= max_amount_minor
    │   → AMOUNT_BELOW_MINIMUM / AMOUNT_ABOVE_MAXIMUM
    │
    └─ Stage 8: capability (optional — only when requiredCapability set on query)
        capability_flags[requiredCapability] = true
        → CAPABILITY_NOT_SUPPORTED
```

**Note**: `country_restrictions` is evaluated as "if non-empty, the countryCode MUST NOT be in the list" (it is a deny-list per existing P02 convention). If `country_restrictions = []`, no country is restricted.
