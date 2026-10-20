# ADM-P1-022 files changed

32 scoped implementation/test/config/catalogue files; existing unrelated workspace changes are excluded. Feature documents, logs, browser result JSON and six screenshots are recorded separately under artifacts in the SHA256 manifest.

- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Application/Services/FxRateAdminService.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Application/Services/AdminFxManagementService.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Domain/Contracts/FxRateProviderInterface.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Domain/Exceptions/FxProviderUnavailableException.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Infrastructure/Providers/UnavailableFxRateProvider.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Infrastructure/Models/FxRateModel.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Infrastructure/Repositories/EloquentFxRateRepository.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Infrastructure/Migrations/2026_10_15_022000_add_fx_provenance.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Infrastructure/Migrations/2026_10_15_022100_currency_change_applications.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Providers/MultiCurrencyServiceProvider.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Http/Resources/FxRateResource.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Http/Controllers/Admin/AdminFxManagementController.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Http/Controllers/Admin/AdminCurrencyController.php`
- `wallet-ecosystem/services/api/app/Modules/MultiCurrency/Http/Controllers/Admin/AdminFxRateController.php`
- `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Validators/FinancialPolicyConfigValidator.php`
- `wallet-ecosystem/services/api/app/Modules/Platform/Application/Services/ConfigurationChangeRequestService.php`
- `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Persistence/ConfigurationChangeRequestModel.php`
- `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Persistence/ConfigurationApprovalModel.php`
- `wallet-ecosystem/services/api/routes/modules/admin-multi-currency.php`
- `wallet-ecosystem/services/api/openapi/v1/admin_fx.yaml`
- `wallet-ecosystem/services/api/tests/Feature/Modules/MultiCurrency/AdminFxManagementTest.php`
- `wallet-ecosystem/services/api/tests/Unit/Modules/MultiCurrency/FxRateDecimalTest.php`
- `wallet-ecosystem/apps/admin/src/modules/multi-currency/pages/currencies.tsx`
- `wallet-ecosystem/apps/admin/src/modules/multi-currency/pages/fx-rates.tsx`
- `wallet-ecosystem/apps/admin/src/lib/navigation/nav-items.ts`
- `wallet-ecosystem/apps/admin/lib/api/admin-app-client.ts`
- `wallet-ecosystem/apps/admin/middleware.ts`
- `wallet-ecosystem/apps/admin/__tests__/features/multi-currency/p1022.test.tsx`
- `wallet-ecosystem/apps/admin/e2e/p1022-fx.spec.ts`
- `wallet-ecosystem/apps/admin/playwright.p1022.config.ts`
- `.specify/feature.json`
- `docs/admin-p1p2-epic-catalogue.md`

Manifest: files-changed.json. Status and pending work: implementation-status.md and tasks.md.
