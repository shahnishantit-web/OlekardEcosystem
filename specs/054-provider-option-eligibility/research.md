# Research: P05 — Provider Registry and Option Eligibility

**Date**: 2026-10-21

All research decisions are derivable from the existing codebase and E48 architecture. No external API research needed.

## Existing Infrastructure (confirmed)

| Component | Path | Relevant Detail |
|-----------|------|-----------------|
| PaymentProviderModel | `app/Modules/Payment/Infrastructure/Models/PaymentProviderModel.php` | `is_enabled`, `is_suspended`, `adapter_identifier`, `sort_order` |
| PaymentProviderAccountModel | `app/Modules/Payment/Infrastructure/Models/PaymentProviderAccountModel.php` | JSONB arrays: `enabled_organisations`, `enabled_modules`, `supported_currencies`, `country_restrictions`, `capability_flags` |
| PaymentAttemptModel | `app/Modules/Payment/Infrastructure/Models/PaymentAttemptModel.php` | `provider_account_id` exists; `provider_account_version` absent → add |
| PaymentConfigChangeDraftModel | `app/Modules/Payment/Infrastructure/Models/PaymentConfigChangeDraftModel.php` | `change_payload` JSONB, `status` enum |
| ProviderRegistryService | `app/Modules/Payment/Application/Services/ProviderRegistryService.php` | `discoverOptions(OptionsQuery)` — basic mapping, no ordered pipeline |
| PaymentProviderAdminService | `app/Modules/Payment/Application/Services/PaymentProviderAdminService.php` | `createProvider`, `updateProvider`, `suspendProvider`, `rotateCredentialReference`; HIGH_RISK_FIELDS constant |
| PaymentOptionsController | `app/Modules/Payment/Http/Controllers/PaymentOptionsController.php` | Thin; delegates to ProviderRegistryService |
| Providers migration | `app/Modules/Payment/Infrastructure/Migrations/2026_10_18_000002_create_payment_providers_table.php` | Seeds: `olekard_wallet`, `mock_bank` |
| Accounts migration | `app/Modules/Payment/Infrastructure/Migrations/2026_10_18_000003_create_payment_provider_accounts_table.php` | `callback_url` column exists; `config_version` absent → add |

## Decisions

### D-01: EligibilityPipeline extraction

- **Decision**: Create `EligibilityPipeline` in `Application\Services`; it accepts an account + query and returns `EligibilityResult`.
- **Why**: Separation enables independent unit testing of each stage. Admin preview reuses the same pipeline with `evaluateAll()` instead of `filterEligible()`.

### D-02: Adapter allowlist via platform config

- **Decision**: Read from `PlatformConfigurationService::get('payment.adapter_allowlist')`. If not set, fall back to `['olekard_wallet', 'mock_bank']`.
- **Why**: FD03 not in repo; allowlist is small and operator-managed. No new DB table needed now.

### D-03: callback_url prohibition via draft validation only

- **Decision**: `validateDraftPayload()` rejects any non-null `callback_url` in a change payload. Column is not dropped.
- **Why**: Dropping is destructive; P05 scope is additive. E25 uses this column in a future package.

### D-04: Optimistic version conflict detection

- **Decision**: Draft stores `account_config_version_at_draft_time`. Approval reads current version in transaction; mismatch → CONFIGURATION_VERSION_CONFLICT.
- **Why**: Low contention; avoids long lock holding during approval workflow.

### D-05: NULL backward compat for provider_account_version

- **Decision**: `payment_attempts.provider_account_version` is INTEGER NULL. Pre-P05 attempts retain NULL; new attempts always populate it.
- **Why**: Cannot reconstruct historical version numbers retroactively.

### D-06: display_icon_key is a design-system token, not a URL

- **Decision**: Store the token key string (e.g. `icon.payment.wallet`), not an external URL.
- **Why**: Satisfies "no unrestricted endpoint URLs" boundary. Frontend resolves token to asset.
