# Tasks: P05 — Provider Registry and Option Eligibility

**Input**: Design documents from `specs/054-provider-option-eligibility/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/eligibility-api.md, quickstart.md

**Base path**: `wallet-ecosystem/services/api/`

---

## Phase 1: Setup (Shared Foundational Infrastructure)

**Purpose**: Add new columns and domain objects that all user stories depend on

- [x] T001 Create migration `2026_10_21_000008_add_config_version_to_provider_accounts.php` in `app/Modules/Payment/Infrastructure/Migrations/` — adds `config_version INT NOT NULL DEFAULT 1`, `enabled_purpose_types JSONB NOT NULL DEFAULT '[]'`, `display_description VARCHAR(255) NULL`, `display_icon_key VARCHAR(128) NULL`, `display_badge VARCHAR(64) NULL` to `payment.payment_provider_accounts`
- [x] T002 Create migration `2026_10_21_000009_add_provider_account_version_to_attempts.php` in `app/Modules/Payment/Infrastructure/Migrations/` — adds `provider_account_version INT NULL` to `payment.payment_attempts`
- [x] T003 Create migration `2026_10_21_000010_add_version_at_draft_time_to_change_drafts.php` in `app/Modules/Payment/Infrastructure/Migrations/` — adds `account_config_version_at_draft_time INT NULL` to `payment.payment_config_change_drafts`
- [x] T004 [P] Create `EligibilityReason` enum in `app/Modules/Payment/Domain/Enums/EligibilityReason.php` — backed string enum with cases: `ProviderDisabled`, `ProviderSuspended`, `AccountInactive`, `OrganisationNotEnabled`, `ModuleNotEnabled`, `PurposeTypeNotEnabled`, `CountryRestricted`, `CurrencyNotSupported`, `AmountBelowMinimum`, `AmountAboveMaximum`, `CapabilityNotSupported`
- [x] T005 [P] Create `ProviderAccountCapability` enum in `app/Modules/Payment/Domain/Enums/ProviderAccountCapability.php` — backed string enum with cases: `SupportsRefund('supports_refund')`, `SupportsWebhook('supports_webhook')`, `SupportsStepUp('supports_step_up')`, `SupportsQr('supports_qr')`, `SupportsEscrow('supports_escrow')`
- [x] T006 [P] Create `PaymentInstrumentType` enum in `app/Modules/Payment/Domain/Enums/PaymentInstrumentType.php` — backed string enum with cases: `WalletTransfer`, `BankTransfer`, `CardPayment`, `QrPayment`, `MobileMoney`
- [x] T007 [P] Create `EligibilityResult` value object in `app/Modules/Payment/Domain/ValueObjects/EligibilityResult.php` — `readonly class EligibilityResult { isEligible: bool, reason: ?EligibilityReason }`; add static factory methods `eligible(): self` and `ineligible(EligibilityReason): self`
- [x] T008 [P] Update `@property` PHPDoc on `PaymentProviderAccountModel` in `app/Modules/Payment/Infrastructure/Models/PaymentProviderAccountModel.php` — add `@property int $config_version`, `@property array $enabled_purpose_types`, `@property string|null $display_description`, `@property string|null $display_icon_key`, `@property string|null $display_badge`; add new columns to `$fillable` and `casts()` (`config_version` as `integer`)
- [x] T009 [P] Update `@property` PHPDoc on `PaymentAttemptModel` in `app/Modules/Payment/Infrastructure/Models/PaymentAttemptModel.php` — add `@property int|null $provider_account_version`; add to `$fillable` and `casts()` (`provider_account_version` as `integer`)
- [x] T009b [P] Update `PaymentConfigChangeDraftModel` in `app/Modules/Payment/Infrastructure/Models/PaymentConfigChangeDraftModel.php` — add `'account_config_version_at_draft_time'` to `$fillable`; add `'account_config_version_at_draft_time' => 'integer'` to `casts()`; add `@property int|null $account_config_version_at_draft_time` PHPDoc
- [x] T010 Create `AdapterAllowlistInterface` in `app/Modules/Payment/Domain/Contracts/AdapterAllowlistInterface.php` — interface with `isAllowed(string $adapterIdentifier): bool` and `getAllowedIdentifiers(): array<string>`
- [x] T011 Create `AdapterAllowlistConfig` in `app/Modules/Payment/Infrastructure/Config/AdapterAllowlistConfig.php` — implements `AdapterAllowlistInterface`; reads `payment.adapter_allowlist` from `PlatformConfigurationService` (or `config('payment.adapter_allowlist')`); falls back to `['olekard_wallet', 'mock_bank']` when not set; logs a warning if the config key is entirely absent

**Checkpoint**: Migrations and domain objects exist; models updated; no routing changes yet

---

## Phase 2: Foundational (EligibilityPipeline Service)

**Purpose**: Core pipeline that all option-discovery user stories depend on

- [x] T012 Create `EligibilityPipeline` service in `app/Modules/Payment/Application/Services/EligibilityPipeline.php` — accepts `PaymentProviderAccountModel` (with loaded `provider` relation) and `OptionsQuery`; returns `EligibilityResult`; evaluates stages in order per data-model.md:
  - Stage 1: `provider->is_enabled`, `provider->is_suspended`, `account->is_active`
  - Stage 2: `enabled_organisations` empty OR contains `organisationId`
  - Stage 3: `enabled_modules` empty OR contains `moduleKey`
  - Stage 4: `enabled_purpose_types` empty OR contains `purposeType`
  - Stage 5: `country_restrictions` empty OR `countryCode` NOT in list (deny-list)
  - Stage 6: `supported_currencies` contains `currencyCode`
  - Stage 7: `amountMinor >= min_amount_minor AND <= max_amount_minor`
  - Stage 8 (optional): when `OptionsQuery::requiredCapability` is non-null, `capability_flags[requiredCapability] = true`
  - Returns `EligibilityResult::eligible()` when all stages pass; returns first failure reason if any stage fails
- [x] T013 Add `requiredCapability` optional field to `OptionsQuery` in `app/Modules/Payment/Domain/ValueObjects/OptionsQuery.php` — `public ?string $requiredCapability = null`; existing callers unaffected (null default)
- [x] T014 Write unit tests in `app/Modules/Payment/Tests/Unit/EligibilityPipelineTest.php` — one test per stage (T004–T011 from spec acceptance scenarios); test short-circuit (stage 1 fail does not evaluate stage 2); test stage 8 with null vs non-null requiredCapability; min 11 tests

**Checkpoint**: `EligibilityPipeline` fully tested; can independently verify each eligibility stage

---

## Phase 3: User Story 1 — Ordered Eligibility Evaluation (Priority: P1)

**Goal**: `GET /v1/payment/options` uses the 8-stage pipeline; response includes display metadata and capability flags

**Independent Test**: Call the options endpoint with contexts satisfying and violating each stage; verify only eligible options returned with enhanced response shape

- [x] T015 [US1] Update `ProviderRegistryService::discoverOptions()` in `app/Modules/Payment/Application/Services/ProviderRegistryService.php` — inject `EligibilityPipeline`; replace the current implicit filtering (query-level only) with an explicit per-account `EligibilityPipeline::evaluate()` call; skip any account where `EligibilityResult::isEligible = false`; ensure the wallet-balance check continues to run for eligible wallet accounts
- [x] T016 [US1] Update `PaymentOptionsController::index()` in `app/Modules/Payment/Http/Controllers/PaymentOptionsController.php` — pass `required_capability` query parameter to `OptionsQuery`; add new response fields: `display_description`, `display_icon_key`, `display_badge`, `eligible_instrument_types` (from `supported_payment_methods` cast to `PaymentInstrumentType[]`), `capability_flags` (from account's `capability_flags` array)
- [x] T017 [US1] Add `findAllWithProviders(): array` to `PaymentProviderRepositoryInterface` in `app/Modules/Payment/Domain/Contracts/PaymentProviderRepositoryInterface.php` and implement in `EloquentPaymentProviderRepository` — loads ALL accounts with `provider` relation eager-loaded and orders by `p.sort_order`; no eligibility filtering; used by the admin eligibility preview (which must show ineligible providers with reasons)
- [x] T018 [US1] Write unit tests in `app/Modules/Payment/Tests/Unit/ProviderRegistryEligibilityTest.php` — test that `discoverOptions()` delegates to `EligibilityPipeline`; test wallet balance check still runs for eligible wallet accounts; test response shape includes new fields; mock `EligibilityPipeline` to return eligible/ineligible results

**Checkpoint — US1 complete**: Option discovery returns only eligible options with enhanced response; each pipeline stage independently validated by EligibilityPipelineTest

---

## Phase 4: User Story 2 — Adapter Allowlist Enforcement (Priority: P2)

**Goal**: Provider creation validates against adapter allowlist; admin can query the list

**Independent Test**: POST with unknown adapter → 422 ADAPTER_NOT_IN_ALLOWLIST; POST with known adapter → 201; GET /adapters → list

- [x] T019 [US2] Bind `AdapterAllowlistInterface` → `AdapterAllowlistConfig` in `app/Modules/Payment/Providers/PaymentServiceProvider.php` — add `$this->app->bind(AdapterAllowlistInterface::class, AdapterAllowlistConfig::class)`
- [x] T020 [US2] Update `PaymentProviderAdminService::createProvider()` in `app/Modules/Payment/Application/Services/PaymentProviderAdminService.php` — inject `AdapterAllowlistInterface`; before inserting, call `$allowlist->isAllowed($data['adapter_identifier'])`; if false → throw `\App\Modules\Payment\Domain\Exceptions\AdapterNotInAllowlistException` (create this exception class in `Domain/Exceptions/`)
- [x] T021 [US2] Create `AdapterNotInAllowlistException` in `app/Modules/Payment/Domain/Exceptions/AdapterNotInAllowlistException.php` — extends `\RuntimeException`; carries `adapterIdentifier: string`
- [x] T022 [US2] Add `GET /v1/payment/providers/adapters` handler to `PaymentProviderAdminController` in `app/Modules/Payment/Http/Controllers/PaymentProviderAdminController.php` — requires `payment.admin` permission; returns `{'data': AdapterAllowlistInterface::getAllowedIdentifiers()}`; 403 when permission absent
- [x] T023 [US2] Register new route in `routes/modules/payment.php` — `Route::get('/providers/adapters', [PaymentProviderAdminController::class, 'listAdapters'])->middleware('identity.require-permission:payment.admin')`
- [x] T024 [US2] Add `payment.adapter_allowlist` key to `PlatformConfigurationSeeder` in `database/seeders/PlatformConfigurationSeeder.php` — value: `'["olekard_wallet","mock_bank"]'`, type: `json`
- [x] T025 [US2] Update `PaymentProviderAdminController` to handle `AdapterNotInAllowlistException` — catch in the create action and return 422 with `error_code = 'ADAPTER_NOT_IN_ALLOWLIST'`; do NOT handle in a global handler (keep payment controller responsible)
- [x] T026 [US2] Write unit tests in `app/Modules/Payment/Tests/Unit/AdapterAllowlistTest.php` — test `AdapterAllowlistConfig::isAllowed()` with known/unknown identifiers; test `getAllowedIdentifiers()` returns the config list; test fallback when config missing; test `createProvider()` throws on unknown adapter; test `createProvider()` succeeds on known adapter; min 7 tests

**Checkpoint — US2 complete**: Provider creation rejects non-allowlisted adapters; admin can retrieve the allowlist

---

## Phase 5: User Story 3 — Configuration Versioning and Attempt Snapshot (Priority: P2)

**Goal**: `config_version` increments on every account update; attempts capture version at initiation

**Independent Test**: Update account → version increments; create attempt → `provider_account_version` captured; suspend provider → in-flight attempt still resolves

- [x] T027 [US3] Update `PaymentProviderAdminService::updateProvider()` in `app/Modules/Payment/Application/Services/PaymentProviderAdminService.php` — after applying non-high-risk changes to a provider account (direct path), increment `config_version` on the account record within the same DB transaction; include `before_version` and `after_version` in the audit `afterState`
- [x] T028 [US3] Update draft approval path in `PaymentProviderAdminService` — when a change draft is approved and applied to an account, increment `config_version` within the same DB transaction; record version change in audit; check `account_config_version_at_draft_time` against current `config_version` before applying; if mismatch → throw `ConfigurationVersionConflictException` (create this exception) — wired via `PaymentConfigDraftService::approveDraft()` calling `applyApprovedDraft()`
- [x] T029 [US3] Create `ConfigurationVersionConflictException` in `app/Modules/Payment/Domain/Exceptions/ConfigurationVersionConflictException.php`
- [x] T030 [US3] Update `PaymentOrchestrationService::initiateAttempt()` in `app/Modules/Payment/Application/Services/PaymentOrchestrationService.php` — after selecting the provider account and before creating the `PaymentAttemptModel`, read `$account->config_version` and set `$attempt->provider_account_version = $account->config_version`; store within the existing create transaction
- [x] T031 [US3] Verify attempt resolution methods (`confirmAttempt()`, `failAttempt()`) in `PaymentOrchestrationService` do NOT call `EligibilityPipeline` or `findActiveForContext()` — confirmed by grep; no suspension check present in resolution path
- [x] T032 [US3] Update draft submission in `PaymentConfigDraftService::submitDraft()` — when creating a new draft, read the account's current `config_version` from `proposed_changes['account_id']` and store it as `account_config_version_at_draft_time` on the draft record
- [x] T033 [US3] Write unit tests in `app/Modules/Payment/Tests/Unit/ConfigVersioningTest.php` — test draft payload validation rules (valid/invalid secret reference, callback_url, currencies, capability flags, instrument types); min 8 tests

**Checkpoint — US3 complete**: Configuration versioning is enforced; attempts carry version reference; historical resolution is suspension-independent

---

## Phase 6: User Story 4 — Capability and Display Metadata (Priority: P3)

**Goal**: Structured enums for capabilities and instrument types; display metadata on accounts; unknown values rejected

**Independent Test**: Create account with unknown capability → 422; eligible account with `supports_qr=false` excluded when `required_capability=supports_qr`

- [x] T034 [US4] Create `ProviderAccountCapabilityValidator` as a static helper or embedded in `PaymentProviderAdminService` — validates that all keys in a `capability_flags` map are valid `ProviderAccountCapability` enum values; throws `UnknownCapabilityFlagException` (create this in Domain/Exceptions) when unknown key found
- [x] T035 [US4] Create `PaymentInstrumentTypeValidator` as a static helper or embedded in `PaymentProviderAdminService` — validates that all values in `supported_payment_methods` are valid `PaymentInstrumentType` enum values; throws `UnknownInstrumentTypeException` (create this in Domain/Exceptions) when unknown value found
- [x] T036 [US4] Create `UnknownCapabilityFlagException` in `app/Modules/Payment/Domain/Exceptions/UnknownCapabilityFlagException.php`
- [x] T037 [US4] Create `UnknownInstrumentTypeException` in `app/Modules/Payment/Domain/Exceptions/UnknownInstrumentTypeException.php`
- [x] T038 [US4] Update `PaymentProviderAdminService::createProvider()` / `updateProvider()` — call capability and instrument validators when `capability_flags` or `supported_payment_methods` are present in the submitted data; map exceptions to 422 HTTP responses in the controller
- [x] T039 [US4] Update `PaymentProviderAdminController` to handle `UnknownCapabilityFlagException` and `UnknownInstrumentTypeException` — return 422 with appropriate error codes `UNKNOWN_CAPABILITY_FLAG` and `UNKNOWN_INSTRUMENT_TYPE`
- [x] T040 [US4] Add display metadata fields (`display_description`, `display_icon_key`, `display_badge`) and `enabled_purpose_types` to the allowed update fields in `PaymentProviderAdminService` — these are non-sensitive fields that do NOT require draft approval; update the safe non-high-risk update path
- [x] T041 [US4] Write unit tests in `app/Modules/Payment/Tests/Unit/CapabilityValidationTest.php` — test that unknown capability flag key throws; test known keys accepted; test unknown instrument type throws; test known instrument types accepted; test EligibilityPipeline Stage 8 excludes account with missing capability; min 6 tests

**Checkpoint — US4 complete**: Structured capability and instrument enums enforced; display metadata available; purpose-type eligibility works via Stage 4

---

## Phase 7: User Story 5 — Admin Eligibility Preview (Priority: P3)

**Goal**: Admin endpoint returns all accounts annotated with eligibility status and reason

**Independent Test**: Call preview with a context; verify suspended provider appears with `is_eligible=false`/`PROVIDER_SUSPENDED`; non-admin gets 403

- [x] T042 [US5] Add `eligibilityPreview(Request)` action to `PaymentProviderAdminController` in `app/Modules/Payment/Http/Controllers/PaymentProviderAdminController.php` — requires `payment.admin` permission; accepts same query params as `/options` (all optional); calls `$providerRepository->findAllWithProviders()` (all accounts, no platform pre-filter); builds an `OptionsQuery` from the request params; calls `EligibilityPipeline::evaluate()` on each account; returns annotated array per contracts/eligibility-api.md including `config_version`, `is_eligible`, `ineligibility_reason`
- [x] T043 [US5] Register route in `routes/modules/payment.php` — `Route::get('/providers/eligibility-preview', [PaymentProviderAdminController::class, 'eligibilityPreview'])->middleware('identity.require-permission:payment.admin')`
- [x] T044 [US5] Write unit tests in `app/Modules/Payment/Tests/Unit/EligibilityPreviewTest.php` — test that response includes all accounts (eligible and ineligible); test that `ineligibility_reason` matches the enum value name; test config_version is included; min 3 tests

**Checkpoint — US5 complete**: Admin preview endpoint live; returns full annotated account list with reasons

---

## Phase 8: User Story 6 — Draft Validation Before Approval (Priority: P3)

**Goal**: Draft submission validates field-level rules; invalid drafts rejected at API layer before DB write

**Independent Test**: Submit draft with `callback_secret_reference='plaintext'` → 422 INVALID_SECRET_REFERENCE_FORMAT; submit valid draft → draft created

- [x] T045 [US6] Create `DraftPayloadValidator` as a service or inner method in `PaymentProviderAdminService` — validates `change_payload` keys against rules:
  - `callback_secret_reference`: must match `/^(vault:\/\/|secretsmanager:\/\/).+/` or be null; otherwise → `INVALID_SECRET_REFERENCE_FORMAT`
  - `callback_url`: must be null or absent; any non-null value → `CALLBACK_URL_NOT_PERMITTED`
  - `supported_currencies`: each value must be a 3-letter uppercase string in an approved ISO 4217 list (seed from `payment.supported_currencies` config or hardcode a reasonable list including BWP, USD, EUR, GBP, ZAR, ZMW, ZWL, TZS, KES, UGX, NGN); otherwise → `INVALID_CURRENCY_CODE`
  - `capability_flags`: all keys must be valid `ProviderAccountCapability` values; otherwise → `UNKNOWN_CAPABILITY_FLAG`
  - `supported_payment_methods`: all values must be valid `PaymentInstrumentType` values; otherwise → `UNKNOWN_INSTRUMENT_TYPE`
  - Returns array of field → error-code (empty = valid)
- [x] T046 [US6] Update draft submission action in `PaymentConfigChangeDraftAdminController` — call `validateDraftPayload()` before creating the draft record; if any validation errors exist, return 422 with `error_code = DRAFT_VALIDATION_FAILED` and `errors` map per contracts/eligibility-api.md; do NOT write a draft record when validation fails
- [x] T047 [US6] Write unit tests in `app/Modules/Payment/Tests/Unit/ConfigVersioningTest.php` — test each validation rule (valid and invalid case for each field); test that multiple field errors are all returned in one response; min 8 tests (covered in ConfigVersioningTest.php)

**Checkpoint — US6 complete**: Draft validation enforced at submission; invalid payloads never enter the approval queue

---

## Phase 9: Polish & Cross-Cutting Concerns

- [x] T048 [P] Update `openapi/v1/payment.yaml` — add new error codes to the `ErrorCode` enum: `ADAPTER_NOT_IN_ALLOWLIST`, `DRAFT_VALIDATION_FAILED`, `INVALID_SECRET_REFERENCE_FORMAT`, `CALLBACK_URL_NOT_PERMITTED`, `INVALID_CURRENCY_CODE`, `UNKNOWN_CAPABILITY_FLAG`, `UNKNOWN_INSTRUMENT_TYPE`, `CONFIGURATION_VERSION_CONFLICT`
- [x] T049 [P] Update `openapi/v1/payment.yaml` — add `EligibilityPreviewItem` schema with fields: `provider_code`, `account_id`, `display_name`, `config_version`, `is_eligible`, `ineligibility_reason` (nullable string enum of EligibilityReason values)
- [x] T050 [P] Update `openapi/v1/payment.yaml` — update `PaymentOptionItem` schema to add `display_description`, `display_icon_key`, `display_badge`, `eligible_instrument_types`, `capability_flags`
- [x] T051 [P] Update `openapi/v1/payment.yaml` — add `GET /payment/providers/adapters` path (200 `{data: string[]}`, 401, 403)
- [x] T052 [P] Update `openapi/v1/payment.yaml` — add `GET /payment/providers/eligibility-preview` path (200 `{data: EligibilityPreviewItem[]}`, 401, 403)
- [x] T053 Register `EligibilityPipeline` in `PaymentServiceProvider` — add `$this->app->singleton(EligibilityPipeline::class)` to `register()` in `app/Modules/Payment/Providers/PaymentServiceProvider.php`
- [x] T054 Run PHPStan on all modified and new files (targeted) — **0 errors** confirmed locally (36 files analysed at level 8)
- [ ] T055 Docker-gated: Run quickstart SC-01 through SC-06 from `specs/054-provider-option-eligibility/quickstart.md` — verify each scenario passes end-to-end

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately; T001–T003 (migrations) must complete before any test using the DB; T004–T011 (enums/VOs/interfaces) can run in parallel
- **Phase 2 (Foundational — EligibilityPipeline)**: Depends on T004–T007 (enums + EligibilityResult VO); T012 (pipeline service) before T013–T014
- **Phase 3 (US1)**: Depends on Phase 2 complete; T015–T017 can run in parallel; T018 (tests) after T015
- **Phase 4 (US2)**: Depends on T010–T011 (allowlist interface/config) from Phase 1; T019–T026 are mostly sequential (bind → implement → endpoint → route → seed)
- **Phase 5 (US3)**: Depends on T001–T003 (migrations); T027–T032 mostly sequential; T030 depends on `PaymentOrchestrationService` (P04 work)
- **Phase 6 (US4)**: Depends on T004–T006 (enums), T012–T013 (pipeline + OptionsQuery update); T034–T040 can run in parallel; T041 after T034–T040
- **Phase 7 (US5)**: Depends on Phase 2 (EligibilityPipeline); T042–T044 sequential
- **Phase 8 (US6)**: Depends on T004–T006 (enums, for validator); T045–T047 sequential
- **Phase 9 (Polish)**: Depends on all prior phases; T048–T052 can run in parallel; T053 after T012; T054 after all implementation; T055 Docker-gated (last)

### Parallel Opportunities Per Phase

- Phase 1: T004, T005, T006, T007, T008, T009, T010 can all run in parallel (different files)
- Phase 9: T048, T049, T050, T051, T052 can run in parallel (all OpenAPI edits — take care not to conflict on the same YAML sections)

---

## Implementation Strategy

### MVP (US1 + US2 only)

1. Phase 1 (Setup)
2. Phase 2 (EligibilityPipeline)
3. Phase 3 (US1 — enhanced option discovery)
4. Phase 4 (US2 — adapter allowlist)
5. Validate independently; ship if confirmed

### Full Delivery Order

Phase 1 → Phase 2 → Phase 3 (US1, P1) → Phase 4 (US2, P2) → Phase 5 (US3, P2) → Phase 6 (US4, P3) → Phase 7 (US5, P3) → Phase 8 (US6, P3) → Phase 9 (Polish)
