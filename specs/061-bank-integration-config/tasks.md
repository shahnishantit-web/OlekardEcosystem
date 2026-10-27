# Tasks: P12 — Dynamic Bank Integration Configuration and Certification

**Input**: Design documents from `specs/061-bank-integration-config/`

**Prerequisites**: spec.md ✓, plan.md ✓, data-model.md ✓, research.md ✓, contracts/ ✓, quickstart.md ✓

**Stack**: PHP 8.4 / Laravel 11 (API) · Next.js 14 + TypeScript (Admin) · Flutter 3.x (Mobile)

**Key constraint**: All 8 stories build on the same `payment` schema; Phase 2 (Foundational) MUST complete before any story work begins.

---

## Phase 1: Setup

**Purpose**: Permission registration and foundational infrastructure hooks.

- [X] T001 Register `payment.configuration` permission scope in `app/Modules/Payment/Providers/PaymentServiceProvider.php` using the E04 permission registration pattern; seed permission record via migration or seeder
- [X] T002 [P] Add `payment.configuration` permission gate to `app/Modules/Platform/Domain/Contracts/PermissionRegistry.php` (or equivalent E04 registration point)

---

## Phase 2: Foundational (Blocking Prerequisites — MUST complete before all stories)

**Purpose**: Schema migrations, Eloquent models, enums, value objects, exceptions, and repository interfaces. No story can start until this phase is complete.

### Migrations

- [X] T003 [P] Create migration `2026_10_24_000001_add_bank_metadata_to_providers.php` in `app/Modules/Payment/Infrastructure/Migrations/` — adds to `payment.payment_providers`: `bank_lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'draft'`, `supported_countries JSONB NOT NULL DEFAULT '[]'`, `organisation_ownership UUID NULL`, `description TEXT NULL`, `logo_reference VARCHAR(500) NULL`, `metadata_version INTEGER NOT NULL DEFAULT 1`; add CHECK constraint on `bank_lifecycle_status IN ('draft','active','suspended','retired')`
- [X] T004 [P] Create migration `2026_10_24_000002_add_lifecycle_to_provider_accounts.php` in `app/Modules/Payment/Infrastructure/Migrations/` — adds to `payment.payment_provider_accounts`: `account_lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'draft'`, `submitted_by_actor_id UUID NULL`, `approved_by_actor_id UUID NULL`, `approved_at TIMESTAMPTZ NULL`, `activated_at TIMESTAMPTZ NULL`, `suspended_at TIMESTAMPTZ NULL`, `retired_at TIMESTAMPTZ NULL`, `rejection_reason TEXT NULL`, `active_config_version_id UUID NULL`, `display_label VARCHAR(255) NULL`, `display_sort_order SMALLINT NOT NULL DEFAULT 0`; add CHECK constraint on `account_lifecycle_status`
- [X] T005 [P] Create migration `2026_10_24_000003_create_integration_profiles_table.php` in `app/Modules/Payment/Infrastructure/Migrations/` — creates `payment.integration_profiles` with all columns from data-model.md; unique index on `(profile_identifier, version)`
- [X] T006 [P] Create migration `2026_10_24_000004_create_api_operation_configs_table.php` in `app/Modules/Payment/Infrastructure/Migrations/` — creates `payment.api_operation_configs` with all columns from data-model.md; CHECK `is_payout = false`; CHECK `operation_type IN ('initiate','query','cancel','full_refund','partial_refund','callback','reconciliation')`; CHECK `http_method IN ('GET','POST','PUT','PATCH')`
- [X] T007 [P] Create migration `2026_10_24_000005_create_configuration_versions_table.php` in `app/Modules/Payment/Infrastructure/Migrations/` — creates `payment.configuration_versions` with all columns from data-model.md; unique index on `(account_id, version_number)`
- [X] T008 [P] Create migration `2026_10_24_000006_create_certification_evidence_table.php` in `app/Modules/Payment/Infrastructure/Migrations/` — creates `payment.certification_evidence` with all columns from data-model.md; index on `(account_id, evidence_type)`
- [X] T009 [P] Create migration `2026_10_24_000007_create_consumer_payment_options_table.php` in `app/Modules/Payment/Infrastructure/Migrations/` — creates `payment.consumer_payment_options` with all columns from data-model.md; index on `(account_id, is_active)` and `(payment_method, is_active)`
- [X] T010 [P] Create migration `2026_10_24_000008_create_credential_rotation_log_table.php` in `app/Modules/Payment/Infrastructure/Migrations/` — creates `payment.credential_rotation_log` with all columns from data-model.md
- [X] T003b [P] Create migration `2026_10_24_000009_add_configuration_version_id_to_attempts.php` in `app/Modules/Payment/Infrastructure/Migrations/` — adds `configuration_version_id UUID NULL` to `payment.payment_attempts` with FK `REFERENCES payment.configuration_versions(id) ON DELETE SET NULL`; partial index `ON (configuration_version_id) WHERE configuration_version_id IS NOT NULL`. Required by FR-023: each attempt MUST record the configuration version used at initiation time. Confirmed absent from existing P06/P10 migrations (verified: P06 added `provider_account_version INTEGER` only).
- [X] T011 Seed `payment.integration_profiles` with `mock-bank-v1` and `http-redirect-v1` profile records in a dedicated seeder `database/seeders/Payment/IntegrationProfileSeeder.php`

### Enums

- [X] T012 [P] Create `app/Modules/Payment/Domain/Enums/BankLifecycleStatus.php` with cases: `Draft`, `Active`, `Suspended`, `Retired`; add `from()`, `label()`, and `allowedTransitionsFrom()` helper methods
- [X] T013 [P] Create `app/Modules/Payment/Domain/Enums/AccountConfigLifecycleStatus.php` with cases matching data-model.md state machine; add `allowedTransitionsFrom()` and `requiresApproverDistinct()` helpers

### Value Objects

- [X] T014 [P] Create `app/Modules/Payment/Domain/ValueObjects/SecretReference.php` — `readonly class SecretReference { public string $keyName; public string $masked = '****'; }` — implements `JsonSerializable`; `jsonSerialize()` returns `['key' => $keyName, 'masked' => '****']`; constructor rejects empty keyName and any string matching a raw-secret pattern (ban `Bearer `, `sk_`, `pk_`, base64 > 40 chars)
- [X] T015 [P] Create `app/Modules/Payment/Domain/ValueObjects/ApiOperationConfig.php` — typed value object with all operation config fields from data-model.md `ApiOperationConfig` entity; includes `fieldMappings` as typed `FieldMappingRule[]` array

### Exceptions

- [X] T016 [P] Create `app/Modules/Payment/Domain/Exceptions/SsrfViolationException.php` extending `DomainException`; message includes offending URL pattern
- [X] T017 [P] Create `app/Modules/Payment/Domain/Exceptions/LifecycleTransitionException.php` with `fromStatus`, `toStatus`, and `reason` properties
- [X] T018 [P] Create `app/Modules/Payment/Domain/Exceptions/ApprovalConflictException.php` — thrown when `approver_actor_id === submitted_by_actor_id`

### Eloquent Models

- [X] T019 Update `app/Modules/Payment/Infrastructure/Models/PaymentProviderModel.php` — add PHPDoc for new columns; add `bank_lifecycle_status`, `supported_countries`, `organisation_ownership`, `description`, `logo_reference`, `metadata_version` to `$fillable` and `$casts`
- [X] T020 Update `app/Modules/Payment/Infrastructure/Models/PaymentProviderAccountModel.php` — add PHPDoc and fillable/casts for all new lifecycle columns from T004
- [X] T021 [P] Create `app/Modules/Payment/Infrastructure/Models/IntegrationProfileModel.php` — `$connection = 'payment'`, `$table = 'payment.integration_profiles'`; `$fillable`, `$casts` (jsonb → array), PHPDoc for all columns
- [X] T022 [P] Create `app/Modules/Payment/Infrastructure/Models/ApiOperationConfigModel.php` — `$connection = 'payment'`, `$table = 'payment.api_operation_configs'`; `belongsTo` to `PaymentProviderAccountModel` and `ConfigurationVersionModel`; full `$fillable` and `$casts`
- [X] T023 [P] Create `app/Modules/Payment/Infrastructure/Models/ConfigurationVersionModel.php` — `$connection = 'payment'`, `$table = 'payment.configuration_versions'`; `belongsTo` to `PaymentProviderAccountModel`; `snapshot` cast to `array`; `hasMany` to `PaymentAttemptModel` via `configuration_version_id` (added by T003b — FR-023 compliance)
- [X] T024 [P] Create `app/Modules/Payment/Infrastructure/Models/CertificationEvidenceModel.php` — `$connection = 'payment'`, `$table = 'payment.certification_evidence'`; `belongsTo` account; `test_run_result` cast to `array`
- [X] T025 [P] Create `app/Modules/Payment/Infrastructure/Models/ConsumerPaymentOptionModel.php` — `$connection = 'payment'`, `$table = 'payment.consumer_payment_options'`; `belongsTo` account; jsonb casts

### Repository Interfaces

- [X] T026 [P] Create `app/Modules/Payment/Domain/Contracts/BankRepositoryInterface.php` with methods: `findById`, `findByCode`, `listPaginated(array $filters, int $perPage)`, `save(PaymentProviderModel $bank)`
- [X] T027 [P] Create `app/Modules/Payment/Domain/Contracts/IntegrationProfileRepositoryInterface.php` with methods: `findByIdentifier(string $identifier, string $version)`, `listActive()`
- [X] T028 [P] Create `app/Modules/Payment/Domain/Contracts/BankAccountLifecycleServiceInterface.php` with all transition methods mirroring LifecycleTransitionRequest actions

**Checkpoint**: All migrations, models, enums, value objects, and exceptions created — all user stories can now proceed.

---

## Phase 3: User Story 1 — Bank and Account Registration (Priority: P1) ★ MVP

**Goal**: Admins can register banks and integration accounts; drive a mock-bank account to Active through the full lifecycle.

**Independent Test**: SC-001 from quickstart.md — add bank, configure, drive to Active; no code changes required for second bank using same profile.

### Implementation — US1

- [X] T029 [US1] Create `app/Modules/Payment/Infrastructure/Repositories/EloquentBankRepository.php` implementing `BankRepositoryInterface`; all queries on `payment` connection; `listPaginated` supports `status`, `code`, `display_name` filter params
- [X] T030 [US1] Create `app/Modules/Payment/Infrastructure/Repositories/EloquentIntegrationProfileRepository.php` implementing `IntegrationProfileRepositoryInterface`
- [X] T031 [US1] Create `app/Modules/Payment/Application/Services/BankAdminService.php` — `listBanks(array $filters, int $perPage)`, `findBank(string $id)`, `createBank(array $data, string $actorId, string $correlationId): PaymentProviderModel`, `updateBank(string $id, array $data, int $metadataVersion, string $actorId): PaymentProviderModel`; optimistic lock on `metadata_version`; all mutations write audit record via AuditService; never exposes raw credentials
- [X] T032 [US1] Create `app/Modules/Payment/Application/Services/IntegrationAccountService.php` — `listAccounts(string $bankId, array $filters)`, `findAccount(string $id)`, `createAccount(array $data, string $actorId): PaymentProviderAccountModel`, `updateAccount(string $id, array $data, int $configVersion, string $actorId): PaymentProviderAccountModel`; updates to Active account create new Draft record; audit all mutations
- [X] T033 [US1] Create `app/Modules/Payment/Http/Controllers/Admin/BankAdminController.php` — thin controller; injects `BankAdminService`; methods: `index`, `show`, `store`, `update`; all return JSON with consistent envelope; requires `payment.configuration` gate
- [X] T034 [US1] Create `app/Modules/Payment/Http/Controllers/Admin/IntegrationAccountAdminController.php` — thin controller; injects `IntegrationAccountService`; methods: `index`, `show`, `store`, `update`; returns draft_created flag when active account edited; requires `payment.configuration` gate
- [X] T035 [US1] Create `app/Modules/Payment/Http/Controllers/Admin/IntegrationProfileAdminController.php` — `index` method returning active profiles list
- [X] T036 [US1] Register admin routes for bank and account endpoints in `app/Modules/Payment/routes/admin.php` (or equivalent route file): `GET/POST /v1/admin/payments/banks`, `GET/PATCH /v1/admin/payments/banks/{bankId}`, `GET/POST /v1/admin/payments/banks/{bankId}/accounts`, `GET/PATCH /v1/admin/payments/banks/{bankId}/accounts/{accountId}`, `GET /v1/admin/payments/integration-profiles`
- [X] T037 [US1] Bind `BankRepositoryInterface`, `IntegrationProfileRepositoryInterface`, and `BankAccountLifecycleServiceInterface` in `app/Modules/Payment/Providers/PaymentServiceProvider.php`
- [X] T038 [US1] Write unit tests in `tests/Unit/Modules/Payment/Services/BankAdminServiceTest.php` — test create, update with optimistic lock conflict, credential-never-returned; also assert `AuditService::record()` is called once for `createBank()` and once for `updateBank()` (constitution P13: config changes must be auditable)
- [X] T039 [US1] Write feature tests in `tests/Feature/Modules/Payment/BankAdminControllerTest.php` — test list, show, create, update; test 403 for user without `payment.configuration` permission; test SC-006 two-bank-same-profile pattern

---

## Phase 4: User Story 2 — API Operation Configuration (Priority: P1)

**Goal**: Admins configure typed API operation mappings for each bank integration account. SSRF-protected, script-injection-blocked, payout-excluded.

**Independent Test**: Configure all operations for mock-bank account; validate against profile schema; save with HTTPS URL; attempt non-HTTPS → validation error.

### Implementation — US2

- [X] T040 [US2] Create `app/Modules/Payment/Domain/Contracts/SsrfGuardInterface.php` with single method `validate(string $url): void` throwing `SsrfViolationException` on violation
- [X] T041 [US2] Create `app/Modules/Payment/Application/Services/SsrfGuard.php` implementing `SsrfGuardInterface` — validates: (a) scheme must be `https`; (b) resolved host not in RFC1918 ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16), loopback (127.0.0.0/8, ::1), link-local (169.254.0.0/16), or metadata IPs (169.254.169.254, fd00:ec2::254); (c) port must be 443 or absent; uses `gethostbyname()` for resolution; returns fast on non-resolving hosts
- [X] T042 [US2] Write unit tests in `tests/Unit/Modules/Payment/Services/SsrfGuardTest.php` — test: non-HTTPS rejected, RFC1918 blocked, loopback blocked, metadata IP blocked, valid HTTPS passes, IPv6 metadata blocked, SC-002 vectors
- [X] T043 [US2] Create `app/Modules/Payment/Application/Services/ApiOperationConfigValidator.php` — validates: (a) `operation_type` is allowed enum; (b) `transform_type` is allowed enum (no `eval`, `exec`, `script`); (c) `base_url` delegates to `SsrfGuard`; (d) `is_payout = false` enforced; (e) `timeout_ms` ≤ 120000; (f) at least `success_status_values` or `failure_status_values` non-empty; returns typed validation errors per field
- [X] T044 [US2] Create `app/Modules/Payment/Application/Services/ApiOperationConfigService.php` — `listOperations(string $accountId)`, `saveOperations(string $accountId, array $operations, string $actorId): array` — validates all operations via `ApiOperationConfigValidator`, calls `SsrfGuard`, creates draft if account is Active, persists to `api_operation_configs`, writes audit record; `validateOperations(string $accountId, array $operations): array` (validate-only, no persist)
- [X] T044b [US2] Wire stored `api_operation_configs` into `app/Modules/Payment/Infrastructure/Adapters/AbstractBankPaymentAdapter.php` (or equivalent adapter base class) — add `loadOperationConfig(string $accountId, string $operation, string $configVersionId): ApiOperationConfig` protected method that reads from `api_operation_configs` table for the given account + config version; replace any hard-coded URL/method/mapping constants in the adapter dispatch flow with values from this loaded config. Without this wiring, stored operation configs are never used at runtime (FR-008 compliance). Create interface method `getOperationConfig(string $operation): ApiOperationConfig` in adapter contract.
- [X] T045 [US2] Create `app/Modules/Payment/Http/Controllers/Admin/ApiOperationConfigAdminController.php` — methods: `index`, `save` (PUT), `validate` (POST to /validate sub-route); injects `ApiOperationConfigService`; returns draft_created flag
- [X] T046 [US2] Register routes: `GET /v1/admin/payments/banks/{bankId}/accounts/{accountId}/operations`, `PUT /v1/admin/payments/banks/{bankId}/accounts/{accountId}/operations`, `POST /v1/admin/payments/banks/{bankId}/accounts/{accountId}/operations/validate` in existing payment admin route file
- [X] T047 [US2] Bind `SsrfGuardInterface` → `SsrfGuard` and `ApiOperationConfigService` in `PaymentServiceProvider.php`
- [X] T048 [US2] Write unit tests in `tests/Unit/Modules/Payment/Services/ApiOperationConfigValidatorTest.php` — test: payout operation rejected, non-HTTPS rejected via SsrfGuard, script in transform_type rejected, empty status mappings rejected, valid config passes
- [X] T049 [US2] Write feature tests in `tests/Feature/Modules/Payment/ApiOperationConfigAdminControllerTest.php` — test: save operations, SSRF rejection (SC-002), payout rejection, validate endpoint, edit-active-creates-draft (SC-008)

---

## Phase 5: User Story 3 — Configuration Lifecycle and Approval (Priority: P1)

**Goal**: Full lifecycle state machine with two-person approval enforced at service layer.

**Independent Test**: Drive mock-bank account through all states; verify same-actor approval blocked (SC-003); verify rejection returns to Draft with reason.

### Implementation — US3

- [X] T050 [US3] Create `app/Modules/Payment/Application/Services/BankAccountLifecycleService.php` implementing `BankAccountLifecycleServiceInterface` — state machine with methods: `submitForValidation`, `initiateSandboxTesting`, `submitEvidence`, `approve(string $accountId, string $approverActorId)`, `reject(string $accountId, string $reason, string $actorId)`, `requestChanges`, `activate`, `suspend`, `reactivate`, `retire`; `approve()` throws `ApprovalConflictException` if `approver === submitted_by`; `retire()` calls obligation check; `activate()` creates `ConfigurationVersion` snapshot; all transitions validate via `AccountConfigLifecycleStatus::allowedTransitionsFrom()`; all transitions write audit record; all transitions use `DB::connection('payment')->transaction()`. **FR-020 policy (clarified)**: ALL edits to an Active account MUST create a new Draft version regardless of which fields changed; there is no lightweight "minor edit" path — this simplifies implementation and ensures the full lifecycle (including two-person approval) is always traversed before any change reaches Active. Document this policy with an inline comment in `updateAccount()` in `IntegrationAccountService`.
- [X] T051 [US3] Add `createVersionSnapshot(string $accountId, string $actorId): ConfigurationVersionModel` private method to `BankAccountLifecycleService` — collects all `api_operation_configs` for account into JSON snapshot, increments `version_number`, sets `active_config_version_id` on account
- [X] T052 [US3] Create `app/Modules/Payment/Http/Controllers/Admin/BankLifecycleAdminController.php` — single `transition(Request $request, string $bankId, string $accountId): JsonResponse` method; validates `action` enum; delegates to `BankAccountLifecycleService`; catches `LifecycleTransitionException` → 409, `ApprovalConflictException` → 409 with code `approval_conflict`
- [X] T053 [US3] Register route: `POST /v1/admin/payments/banks/{bankId}/accounts/{accountId}/lifecycle` in payment admin route file
- [X] T054 [US3] Write unit tests in `tests/Unit/Modules/Payment/Services/BankAccountLifecycleServiceTest.php` — test all valid transitions, invalid transitions throw `LifecycleTransitionException`, SC-003 same-actor approval blocked, activate creates ConfigurationVersion, reject stores reason; also assert `AuditService::record()` is called for each transition (at minimum: submitForValidation, approve, activate, suspend — constitution P13 audit coverage)
- [X] T055 [US3] Write feature tests in `tests/Feature/Modules/Payment/BankLifecycleAdminControllerTest.php` — test SC-001 full lifecycle, SC-003 approval conflict 409, invalid transition 409, unauthorized 403; also test **idempotent lifecycle transitions** (constitution P4/P8): submit the `activate` transition twice with the same `Idempotency-Key` UUID; assert second call returns the same `{new_status: "active"}` response without changing state or creating a duplicate `ConfigurationVersion` snapshot

---

## Phase 6: User Story 4 — Secure Credential and Secret Management (Priority: P1)

**Goal**: All credential references use `SecretReference` value object; masked in all responses; rotation creates audit log record.

**Independent Test**: Create account with `callback_secret_reference` key name; GET account → confirm masked; rotate → confirm rotation log; GET responses confirmed credential-free (SC-004).

### Implementation — US4

- [X] T056 [US4] Update `PaymentProviderAccountModel` PHPDoc and cast for `callback_secret_reference` to return `SecretReference` value object via Eloquent accessor; raw value never exposed in `toArray()` or JSON serialisation
- [X] T057 [US4] Update `IntegrationAccountService::createAccount()` and `updateAccount()` to accept `callback_secret_reference` as a plain key name string and store as `SecretReference`; ensure serialised form in all controller responses uses masked form
- [X] T058 [US4] Update `ApiOperationConfigModel` to return `secret_key_references` as array of `{role: key_name, masked: '****'}` in serialisation; never return raw values
- [X] T059 [US4] Create `app/Modules/Payment/Application/Services/CredentialRotationService.php` — `rotate(string $accountId, string $secretRole, string $newKeyName, string $actorId, ?string $reason): CredentialRotationLogModel`; verifies `new_key_name` is non-empty and not a raw-secret-pattern string; writes to `credential_rotation_log`; updates `secret_key_references` on active `api_operation_configs` for that role; writes audit record; throws if account has no operation config using that role. **FR-024 in-flight credential resolution**: add a `resolveCurrentKeyForRole(string $accountId, string $secretRole, ?string $configVersionId): string` method — if `$configVersionId` is provided (from a committed attempt), look up the `credential_rotation_log` ordered by `effective_from DESC` where `effective_from <= attempt.initiated_at` and return the `new_key_name` that was active at that time; if no rotation record exists for the attempt's timeframe, return the key from the original `api_operation_configs` snapshot. Expose this method on `CredentialRotationServiceInterface` for use by `AbstractBankPaymentAdapter` during callback/refund processing.
- [X] T060 [US4] Create `app/Modules/Payment/Http/Controllers/Admin/CredentialRotationAdminController.php` — `rotate` method; injects `CredentialRotationService`; POST `/v1/admin/payments/banks/{bankId}/accounts/{accountId}/rotate-credential`
- [X] T061 [US4] Register credential rotation route in payment admin route file
- [X] T062 [US4] Write unit tests in `tests/Unit/Modules/Payment/Services/CredentialRotationServiceTest.php` — test: rotation creates log record, raw-secret-pattern in new_key_name rejected, audit written; **FR-024 in-flight scenarios**: (a) `resolveCurrentKeyForRole()` with a configVersionId from before rotation returns the OLD key name; (b) same method with configVersionId from after rotation returns the NEW key name; (c) multiple rotations — picks the key active at the attempt's `initiated_at` timestamp
- [X] T063 [US4] Write feature tests in `tests/Feature/Modules/Payment/CredentialMaskingTest.php` — test SC-004: GET account response contains masked reference not raw value; scan response body for raw-secret patterns; rotation endpoint registers log entry

---

## Phase 7: User Story 5 — Sandbox Testing and Certification Evidence (Priority: P2)

**Goal**: Controlled sandbox tests; evidence upload; certification gate before Approved transition.

**Independent Test**: SC-007 — sandbox test creates no payment_intents; evidence upload creates record; lifecycle gate blocks Approved without required evidence.

### Implementation — US5

- [X] T064 [US5] Create `app/Modules/Payment/Application/Services/CertificationEvidenceService.php` — `listEvidence(string $accountId)`, `addEvidence(string $accountId, array $data, string $actorId): CertificationEvidenceModel`, `hasRequiredEvidence(string $accountId): bool`; `hasRequiredEvidence` checks at least one evidence record of each required type (documentation, merchant_ownership) exists; writes audit for each evidence upload
- [X] T065 [US5] Integrate `CertificationEvidenceService::hasRequiredEvidence()` into `BankAccountLifecycleService::submitEvidence()` transition guard — throw `LifecycleTransitionException` with `reason='missing_required_evidence'` if check fails
- [X] T066 [US5] Create `app/Modules/Payment/Application/Services/SandboxTestService.php` — `runConnectivityTest(string $accountId, string $actorId): array` — executes a status-query operation (NOT initiate) using the account's sandbox operation config; records a `test_run` evidence record; MUST NOT create `payment_intents` or `payment_attempts` rows; returns `{result: pass|fail, message, duration_ms, evidence_id}`
- [X] T067 [US5] Create `app/Modules/Payment/Http/Controllers/Admin/CertificationEvidenceAdminController.php` — methods: `index`, `store`, `sandboxTest`; routes: `GET/POST /v1/admin/payments/banks/{bankId}/accounts/{accountId}/evidence`, `POST /v1/admin/payments/banks/{bankId}/accounts/{accountId}/sandbox-test`
- [X] T068 [US5] Register evidence and sandbox-test routes in payment admin route file
- [X] T069 [US5] Write unit tests in `tests/Unit/Modules/Payment/Services/CertificationEvidenceServiceTest.php` — test hasRequiredEvidence true/false, addEvidence creates record and audit, SC-007 sandbox test does not create payment rows
- [X] T070 [US5] Write feature tests in `tests/Feature/Modules/Payment/CertificationEvidenceAdminControllerTest.php` — test evidence upload, sandbox test result, lifecycle gate blocks submit-evidence when hasRequiredEvidence is false

---

## Phase 8: User Story 6 — Consumer Payment Options (Priority: P2)

**Goal**: Mobile app receives dynamically-discovered payment options with display metadata and fee disclosure. Newly activated banks appear immediately. No credentials in response.

**Independent Test**: SC-005 — activate account, GET options, see display_label; suspend account, GET options, label absent; no raw credentials in response.

### Implementation — US6

- [X] T071 [US6] Create `ConsumerPaymentOptionModel` scope `activeForContext(OptionsQuery $query)` joining `payment_provider_accounts` on `account_id` where `is_active = true` and matching purpose/currency/country
- [X] T072 [US6] Extend `ProviderRegistryService::discoverOptions()` in `app/Modules/Payment/Application/Services/ProviderRegistryService.php` to load `ConsumerPaymentOption` for each eligible account; enrich `PaymentProviderOption` value object with `display_label`, `payment_method`, `display_order`, and fee disclosure fields
- [X] T073 [US6] Extend `PaymentProviderOption` value object in `app/Modules/Payment/Domain/ValueObjects/PaymentProviderOption.php` with new fields: `displayLabel`, `paymentMethod`, `displayOrder`, `feeDisclosure`, `availabilityInfo`, `restrictions`
- [X] T074 [US6] Update `PaymentOptionsController::index()` in `app/Modules/Payment/Http/Controllers/PaymentOptionsController.php` to return enriched response matching `consumer-payment-options-api.yaml` contract (display_label, fee_disclosure, availability, supported_next_action); ensure no credential fields leak into response
- [X] T075 [US6] Write unit tests in `tests/Unit/Modules/Payment/Services/ProviderRegistryServiceOptionsTest.php` — test display_label from ConsumerPaymentOption; suspended account absent; no credential fields in serialised option
- [X] T076 [US6] Write feature tests in `tests/Feature/Modules/Payment/PaymentOptionsEnhancedTest.php` — test SC-005: activate bank → appears in options; suspend → absent; SC-006 two banks same profile both appear; no credentials in response
- [X] T076b [US6] Update Flutter `wallet-ecosystem/apps/customer/lib/payments/payment_options_service.dart` (E5 fix) — extend `PaymentOption` model to include `displayLabel`, `feeDisclosure` (serviceFeeMinor, providerFeeMinor, totalFeeMinor, totalPayableMinor), `paymentMethod`, `supportedNextAction`, `restrictions`; update `fetchOptions()` to deserialise these fields from the enhanced API response; update options list widget to display `displayLabel` and fee summary; `flutter analyze` must pass clean

---

## Phase 9: User Story 7 — Disable, Rotation, and Safe Recovery (Priority: P2)

**Goal**: Disabling blocks new attempts while preserving callbacks/refunds for existing attempts. Retirement gate enforced.

**Independent Test**: Create payment attempt on active account; suspend account; new intent rejected; existing attempt callback still accepted.

### Implementation — US7

- [X] T077 [US7] Add `isEligibleForNewAttempts(): bool` method to `PaymentProviderAccountModel` — returns false if `account_lifecycle_status` is `suspended` or `retired`
- [X] T078 [US7] Update `EligibilityPipeline` in `app/Modules/Payment/Application/Services/EligibilityPipeline.php` to call `isEligibleForNewAttempts()` as a blocking stage; eligibility result `isEligible = false` if account is suspended/retired
- [X] T079 [US7] Add `hasOutstandingObligations(string $accountId): bool` method to `BankAccountLifecycleService` — checks for `payment_attempts` in non-terminal states linked to the account; `retire()` transition guard calls this; throws `LifecycleTransitionException` with `reason='outstanding_obligations'` unless a `resolution_route_recorded` flag is passed
- [X] T080 [US7] Ensure `PaymentCallbackService` in `app/Modules/Payment/Application/Services/PaymentCallbackService.php` resolves callback via `configuration_version_id` on `payment_attempts` (column added by T003b), not the current account status; load `ConfigurationVersionModel` to obtain the original operation config snapshot; use `CredentialRotationService::resolveCurrentKeyForRole()` for credential lookup; callbacks continue processing for suspended/retired accounts
- [X] T081 [US7] Write unit tests in `tests/Unit/Modules/Payment/Services/DisableAccountTest.php` — test suspended account excluded from EligibilityPipeline; retired account excluded; existing attempt callback accepted regardless of account status
- [X] T082 [US7] Write feature tests in `tests/Feature/Modules/Payment/AccountDisableRecoveryTest.php` — test: suspend account → new intent 422 provider-unavailable; existing attempt callback 200; retire blocked by outstanding obligations

---

## Phase 10: User Story 8 — Admin Panel (Priority: P2)

**Goal**: Next.js admin pages for all bank configuration management with full state coverage.

**Independent Test**: Navigate each page as Payment Configuration admin; complete Add Bank → Configure → Activate flow; verify denied page for user without permission.

### Implementation — US8

- [X] T083 [US8] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/banks/page.tsx` — bank list page with status badge filter, paginated table, Add Bank button; loading/empty/error states
- [X] T084 [US8] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/banks/new/page.tsx` — add bank form with fields from `CreateBankRequest`; adapter profile selector from integration profiles endpoint; validation errors; loading state
- [X] T085 [US8] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/banks/[id]/page.tsx` — bank detail page with metadata, accounts list, lifecycle status badge, Edit metadata action; pending-approval and conflicting-edit states
- [X] T086 [US8] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/banks/[id]/accounts/page.tsx` — integration account list with lifecycle status; add account button
- [X] T087 [US8] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/banks/[id]/accounts/[accountId]/page.tsx` — account detail page; lifecycle controls panel (submit/approve/reject/activate/suspend/retire buttons with confirmation dialogs); credential reference fields (masked display); configuration version history table; all lifecycle states rendered
- [X] T088 [US8] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/banks/[id]/accounts/[accountId]/operations/page.tsx` — API operation configuration editor; per-operation expandable form sections; HTTPS URL validation inline; field mapping rows; status value arrays; save with draft-created banner when active; validate-without-save button
- [X] T089 [US8] [P] Create `wallet-ecosystem/apps/admin/app/(admin)/payments/banks/[id]/accounts/[accountId]/evidence/page.tsx` — certification evidence list; upload form; sandbox test trigger button with result display; evidence gate status indicator
- [X] T090 [US8] [P] Create shared component `wallet-ecosystem/apps/admin/app/(admin)/payments/components/BankStatusBadge.tsx` — status badge with colour coding for bank and account lifecycle states
- [X] T091 [US8] [P] Create shared component `wallet-ecosystem/apps/admin/app/(admin)/payments/components/SecretReferenceField.tsx` — displays `key_name` as label + `****` masked value; never renders raw secret; copy-key-name button only
- [X] T092 [US8] [P] Create shared component `wallet-ecosystem/apps/admin/app/(admin)/payments/components/LifecyclePanel.tsx` — renders lifecycle action buttons based on current status; confirmation modal; pending-approval state; approval-conflict error display
- [X] T093 [US8] [P] Create shared component `wallet-ecosystem/apps/admin/app/(admin)/payments/components/ApiOperationEditor.tsx` — full form for one operation type; field mapping row editor; HTTPS URL indicator
- [X] T094 [US8] Create admin API client hooks in `wallet-ecosystem/apps/admin/lib/api/payments/banks.ts` — typed React hooks/fetch wrappers for all admin bank and account endpoints
- [X] T095 [US8] Write TypeScript tests in `wallet-ecosystem/apps/admin/__tests__/payments/banks/` — test BankStatusBadge renders all status states; SecretReferenceField never renders raw values; LifecyclePanel renders correct actions per status; ApiOperationEditor rejects non-HTTPS input

---

## Phase 11: Polish & Cross-Cutting Concerns

**Purpose**: Security validation, two-bank zero-code-change demo, documentation, PHPStan, quickstart validation.

- [X] T096 [P] Write dedicated SSRF security tests in `tests/Unit/Modules/Payment/Security/SsrfGuardSecurityTest.php` — cover all 9 SSRF vectors: http, private-IP 10.x, private-IP 172.16.x, private-IP 192.168.x, loopback 127.x, loopback ::1, link-local 169.254.x, metadata IP 169.254.169.254, IPv6 metadata fd00:ec2::254; all must return `SsrfViolationException`
- [X] T097 [P] Write two-person approval enforcement test in `tests/Feature/Modules/Payment/TwoPersonApprovalTest.php` — SC-003: confirm `approved_by ≠ submitted_by` enforced in 100% of approval attempts by original submitter; attempt approval as 3 different same-actor scenarios; all must return 409
- [X] T098 [P] Write credential-never-in-response tests in `tests/Feature/Modules/Payment/CredentialAbsenceTest.php` — SC-004: scan all account detail, operation config, options, and replay responses for raw-secret patterns (`Bearer `, `sk_`, raw base64 > 40 chars, known test key values)
- [X] T099 [P] Write unknown-provider-status test in `tests/Feature/Modules/Payment/UnknownProviderStatusTest.php` — SC-002 variant: inject unknown status value in mock adapter response; assert attempt remains in unresolved state; assert no `payment_intents.accounting_status` advances to `success`
- [X] T100 [P] Write two-bank-same-profile zero-code-change test in `tests/Feature/Modules/Payment/TwoBankSameProfileTest.php` — SC-006: configure and activate two banks using `http-redirect-v1` profile via DB fixtures; call `ProviderRegistryService::discoverOptions()`; assert both banks returned; assert zero changes to Payment domain service files (use git diff or file hash)
- [X] T101 Write `docs/operations/bank-integration-runbook.md` — covers: add bank, configure, lifecycle, credential rotation, disable, retire, sandbox test, certification evidence, common errors and recovery steps
- [ ] T102 [P] Run PHPStan level 8 on all P12 new/modified PHP files: `./vendor/bin/phpstan analyse app/Modules/Payment/Application/Services/BankAdminService.php app/Modules/Payment/Application/Services/BankAccountLifecycleService.php app/Modules/Payment/Application/Services/SsrfGuard.php app/Modules/Payment/Application/Services/ApiOperationConfigService.php app/Modules/Payment/Application/Services/CertificationEvidenceService.php --level=8` — (Docker-gated; report 0 errors required)
- [ ] T103 Run quickstart.md validation scenarios SC-001 through SC-008 using Docker: `docker compose up -d` then execute each scenario curl sequence; all expected outputs must match — (Docker-gated)

### Performance SC Manual Verification Protocol (B1/B2/B3/B4)

The following success criteria contain numeric latency targets that are **manually verified** during Docker-gated acceptance testing, not enforced by automated unit/feature tests:

| SC | Target | Verification Method |
|----|--------|---------------------|
| SC-001 | Full lifecycle < 30 min via admin panel | Time a complete Add Bank → Activate flow by a new operator using only the UI |
| SC-003 | Disable takes effect within 5 s | Issue suspend API call; immediately POST new intent; confirm 422 within 5 s |
| SC-007 | Sandbox test completes < 10 s | Check `duration_ms` field in sandbox test API response; must be < 10000 |
| SC-008 | Audit log entry retrievable within 1 s | After any config change, query audit log endpoint; assert entry appears immediately |

Add a `docs/operations/bank-integration-runbook.md` section titled "Performance Acceptance Checks" (part of T101) covering these four manual steps.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately
- **Phase 2 (Foundational)**: Depends on Phase 1 — **BLOCKS all stories**
- **Phase 3 (US1)**: Depends on Phase 2 completion — foundational repository + admin service layer
- **Phase 4 (US2)**: Depends on Phase 2 completion — can run in parallel with Phase 3
- **Phase 5 (US3)**: Depends on Phase 3 (US1) completion — lifecycle service uses account models from US1
- **Phase 6 (US4)**: Depends on Phase 3 (US1) completion — credential masking needs account service from US1
- **Phase 7 (US5)**: Depends on Phase 5 (US3) — certification evidence gate integrates into lifecycle service
- **Phase 8 (US6)**: Depends on Phase 3 (US1) completion — consumer options needs active accounts
- **Phase 9 (US7)**: Depends on Phase 5 (US3) — disable/retire transitions are lifecycle transitions
- **Phase 10 (US8)**: Can begin admin UI skeletons after Phase 3; full wiring requires Phase 5–7 APIs
- **Phase 11 (Polish)**: Depends on all story phases complete

### Story-level Dependencies

```
Phase 2 (Foundational)
  ├── Phase 3 (US1 — Bank Registration) ──┬── Phase 5 (US3 — Lifecycle)
  ├── Phase 4 (US2 — API Config)           ├── Phase 7 (US5 — Evidence)
  │                                        ├── Phase 6 (US4 — Credentials)
  │                                        ├── Phase 8 (US6 — Consumer Options)
  │                                        └── Phase 9 (US7 — Disable/Retire)
  └──────────────────────────────────────── Phase 10 (US8 — Admin Panel)
```

### Parallel Opportunities

- T003–T011 (all migrations): all [P] — run simultaneously
- T012–T015 (enums + value objects): all [P] — run simultaneously
- T016–T018 (exceptions): all [P] — run simultaneously
- T021–T025 (new Eloquent models): all [P] — run simultaneously
- T026–T028 (repository interfaces): all [P] — run simultaneously
- T096–T100 (polish tests): all [P] — run simultaneously
- US4, US5, US6, US7 (after US1 complete): can proceed in parallel

---

## Parallel Example: Phase 2 Foundational

```bash
# All 9 migrations can be written simultaneously:
Task T003: add_bank_metadata_to_providers migration
Task T004: add_lifecycle_to_provider_accounts migration
Task T005: create_integration_profiles_table migration
Task T006: create_api_operation_configs_table migration
Task T007: create_configuration_versions_table migration
Task T008: create_certification_evidence_table migration
Task T009: create_consumer_payment_options_table migration
Task T010: create_credential_rotation_log_table migration

# All model/enum files can be written simultaneously after migrations:
Task T012: BankLifecycleStatus enum
Task T013: AccountConfigLifecycleStatus enum
Task T014: SecretReference value object
Task T015: ApiOperationConfig value object
```

---

## Implementation Strategy

### MVP First (US1 + US2 + US3 + US4)

1. Complete Phase 1 + Phase 2 (Setup + Foundational)
2. Complete Phase 3 (US1 — Bank Registration): bank + account CRUD to Active
3. Complete Phase 4 (US2 — API Config): operation config with SSRF guard
4. Complete Phase 5 (US3 — Lifecycle): full state machine with two-person approval
5. Complete Phase 6 (US4 — Credentials): credential masking + rotation
6. **STOP and VALIDATE**: SC-001 full lifecycle; SC-002 SSRF; SC-003 approval conflict; SC-004 credentials
7. All P1 stories complete — release-eligible backend

### Incremental Delivery

1. MVP (P1 stories) → test independently → SC-001–SC-004 pass
2. Add Phase 7 (US5 — Evidence) → SC-007 sandbox test
3. Add Phase 8 (US6 — Consumer Options) → SC-005/SC-006 mobile options
4. Add Phase 9 (US7 — Disable/Retire) → disable/recovery scenarios
5. Add Phase 10 (US8 — Admin Panel) → full UI coverage
6. Phase 11 (Polish) → security tests, PHPStan, quickstart

### Parallel Team Strategy (if staffed)

- After Phase 2 completes:
  - Dev A: Phase 3 (US1 — Bank Registration) → Phase 5 (US3 — Lifecycle) → Phase 7 (US5)
  - Dev B: Phase 4 (US2 — API Config) → Phase 6 (US4 — Credentials) → Phase 9 (US7)
  - Dev C: Phase 8 (US6 — Consumer Options) → Phase 10 (US8 — Admin Panel)

---

## Notes

- [P] tasks = different files, no dependencies on other in-progress tasks
- [USn] label maps task to user story for traceability
- Credential masking via `SecretReference` value object is the single control point — never bypass
- SSRF guard validates at save time; also validate at runtime dispatch in adapters
- Unknown provider status MUST remain unresolved — never default to success
- All lifecycle transitions use `DB::connection('payment')->transaction()` for atomicity
- T102/T103 are Docker-gated; mark `markTestSkipped()` in unit test equivalents when Docker unavailable

## Dummy bank data request (2026-10-26)

- [x] Seed bank1, bank2 and bank3 as disabled draft development placeholders; preserve existing rows and block production seeding.
- [x] Connect the bank list to its authenticated API with status filtering, pagination and explicit failure states.
- [x] Apply the required metadata migration after fixing its PostgreSQL constraint syntax; verify seeded rows and permission.
- [x] Run targeted tests and record existing broader validation blockers in dummy-banks.md.
