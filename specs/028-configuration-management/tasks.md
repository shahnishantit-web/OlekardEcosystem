# Tasks: E28 — Configuration Management

**Input**: Design documents from `specs/028-configuration-management/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Organization**: Phases 1–2 are shared infrastructure. Phases 3–7 map to user stories. Phase 8 is polish and cross-cutting.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks in the same phase)
- **[Story]**: Which user story this task belongs to (US1–US5)

---

## Phase 1: Setup (Config, Enums, Interfaces, Permissions)

**Purpose**: Static scaffolding — config file, PHP enums, validator interface, permissions constants. No DB changes yet.

- [x] T001 Create `config/configuration.php` with `escalation_threshold_hours` (default 24) and `max_history_per_page` (default 50) backed by env vars in `wallet-ecosystem/services/api/config/configuration.php`
- [x] T002 [P] Create `ConfigurationDomain` PHP backed string enum (financial_policy, operational_policy, geographic) in `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Enums/ConfigurationDomain.php`
- [x] T003 [P] Create `ChangeRequestStatus` PHP backed string enum (pending_approval, approved, rejected, withdrawn, failed) in `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Enums/ChangeRequestStatus.php`
- [x] T004 [P] Create `VersionEventType` PHP backed string enum (submitted, approved, rejected, withdrawn, activated, failed) in `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Enums/VersionEventType.php`
- [x] T005 Create `ConfigurationDomainValidatorInterface` with `validate(string $key, mixed $value): void` and `supportsDomain(ConfigurationDomain $domain): bool` in `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Contracts/ConfigurationDomainValidatorInterface.php`
- [x] T006 Add `CONFIGURATION_READ = 'configuration.read'`, `CONFIGURATION_WRITE = 'configuration.write'`, `CONFIGURATION_APPROVE = 'configuration.approve'` constants to `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Contracts/PlatformPermissions.php`
- [x] T007 Create `E28ConfigurationPermissionsSeeder` seeding the three new permissions and assigning them to the super-admin role in `wallet-ecosystem/services/api/database/seeders/E28ConfigurationPermissionsSeeder.php`

**Checkpoint**: Static scaffolding complete — enums, interface, and permission constants all defined.

---

## Phase 2: Foundational (Migrations, Models, Repositories, Routes)

**Purpose**: DB tables, Eloquent models, repository interfaces + implementations, route registration. MUST complete before any user story work begins.

**⚠️ CRITICAL**: No user story tasks can begin until this phase is complete.

- [x] T008 Write migration `2026_10_02_000001_add_domain_to_platform_configurations` — adds `domain VARCHAR(50) NOT NULL DEFAULT 'operational_policy'` to `platform.configurations` (backfill, then drop default); also adds `change_request_id UUID NULLABLE` FK to `platform.configuration_values` referencing `platform.configuration_change_requests.id` ON DELETE SET NULL (enables T027 to write `Activated` version records without a fragile config_key lookup) in `wallet-ecosystem/services/api/database/migrations/2026_10_02_000001_add_domain_to_platform_configurations.php`
- [x] T009 Write migration `2026_10_02_000002_create_platform_configuration_change_requests` with all columns, unique partial index `(config_key) WHERE status = 'pending_approval'`, index on `(status, submitted_at DESC)`, index on `(maker_id)`, unique on `(idempotency_key)` in `wallet-ecosystem/services/api/database/migrations/2026_10_02_000002_create_platform_configuration_change_requests.php`
- [x] T010 Write migration `2026_10_02_000003_create_platform_configuration_approvals` with all columns, indexes on `(change_request_id)` and `(checker_id)` in `wallet-ecosystem/services/api/database/migrations/2026_10_02_000003_create_platform_configuration_approvals.php`
- [x] T011 Write migration `2026_10_02_000004_create_platform_configuration_versions` with all columns, indexes on `(config_key, created_at DESC)`, `(domain, created_at DESC)`, `(maker_id)`, `(checker_id)`, `(event_type, created_at DESC)`, and `REVOKE UPDATE, DELETE ON platform.configuration_versions FROM PUBLIC` in `wallet-ecosystem/services/api/database/migrations/2026_10_02_000004_create_platform_configuration_versions.php`
- [x] T012 [P] Create `ConfigurationChangeRequestModel` Eloquent model with `$fillable`, JSONB casts for `proposed_value`/`previous_value`, `ChangeRequestStatus` enum cast for `status`, `hasOne` approval relationship, `hasMany` versions relationship in `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Persistence/ConfigurationChangeRequestModel.php`
- [x] T013 [P] Create `ConfigurationVersionModel` Eloquent model with `$fillable`, JSONB casts, `VersionEventType` enum cast for `event_type`, `belongsTo` change request relationship in `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Persistence/ConfigurationVersionModel.php`
- [x] T014 [P] Create `ConfigurationApprovalModel` Eloquent model with `$fillable` and `belongsTo` change request relationship in `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Persistence/ConfigurationApprovalModel.php`
- [x] T015 [P] Create `ConfigurationChangeRequestRepositoryInterface` and its Eloquent implementation in `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Contracts/ConfigurationChangeRequestRepositoryInterface.php` and `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Repositories/EloquentConfigurationChangeRequestRepository.php`
- [x] T016 [P] Create `ConfigurationVersionRepositoryInterface` and its Eloquent implementation supporting paginated history queries filterable by date range, maker_id, checker_id in `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Contracts/ConfigurationVersionRepositoryInterface.php` and `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Repositories/EloquentConfigurationVersionRepository.php`
- [x] T017 [P] Create `ConfigurationApprovalRepositoryInterface` and its Eloquent implementation in `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Contracts/ConfigurationApprovalRepositoryInterface.php` and `wallet-ecosystem/services/api/app/Modules/Platform/Infrastructure/Repositories/EloquentConfigurationApprovalRepository.php`
- [x] T018 Extend `wallet-ecosystem/services/api/routes/modules/configuration.php` with admin routes: `GET /v1/admin/configuration/keys`, `GET /v1/admin/configuration/keys/{key}`, `GET /v1/admin/configuration/keys/{key}/history`, `GET|POST /v1/admin/configuration/change-requests`, `GET /v1/admin/configuration/change-requests/{id}`, `POST .../approve`, `POST .../reject`, `POST .../withdraw` — all gated by `auth:sanctum` middleware
- [x] T018b Implement `ConfigurationKeyAdminController` with `index` (list all keys filterable by domain, including `has_pending_change` flag and current value) and `show` (key detail with current value and `pending_change_request_id`) methods, gated on `configuration.read` permission, emitting audit log entry via `AuditService::log()` for each read, in `wallet-ecosystem/services/api/app/Modules/Platform/Http/Controllers/Admin/ConfigurationKeyAdminController.php`
- [x] T018c Create typed Next.js API client for the configuration feature with functions for all 10 contract endpoints (`listKeys`, `getKey`, `getKeyHistory`, `listChangeRequests`, `getChangeRequest`, `submitChangeRequest`, `approveChangeRequest`, `rejectChangeRequest`, `withdrawChangeRequest`) following the existing `lib/api/` pattern in `wallet-ecosystem/apps/admin/lib/api/configuration.ts`

**Checkpoint**: DB migrations runnable, Eloquent models registered, routes defined, key browser controller and frontend API client in place — user story work can begin.

---

## Phase 3: User Story 5 — Maker-Checker Approval Workflow (Priority: P1) 🎯 MVP Core

**Goal**: The complete maker-checker workflow — submit, approve, reject, withdraw — with concurrent approval prevention, self-approval block, version records on every state transition, and the activation hook in `ConfigurationActivationJob`.

**Independent Test**: Submit a change request as admin A; attempt self-approval → 403; approve as admin B → 200; concurrent second approval → 409; verify draft written to `configuration_values`; verify version trail contains `submitted`, `approved`, (within 60s) `activated` events.

- [x] T019 [P] [US5] Create `SubmitChangeRequestDTO` in `wallet-ecosystem/services/api/app/Modules/Platform/Application/DTOs/SubmitChangeRequestDTO.php`
- [x] T020 [P] [US5] Create `ApproveChangeRequestDTO` in `wallet-ecosystem/services/api/app/Modules/Platform/Application/DTOs/ApproveChangeRequestDTO.php`
- [x] T021 [P] [US5] Create `RejectChangeRequestDTO` in `wallet-ecosystem/services/api/app/Modules/Platform/Application/DTOs/RejectChangeRequestDTO.php`
- [x] T022 [P] [US5] Create `WithdrawChangeRequestDTO` in `wallet-ecosystem/services/api/app/Modules/Platform/Application/DTOs/WithdrawChangeRequestDTO.php`
- [x] T023 [US5] Implement `ConfigurationChangeRequestService::submit()` — validate proposed value against domain validator, check one-pending-per-key (throw 409 if pending exists), write `configuration_change_requests` row with `pending_approval` status, write `submitted` version record to `platform.configuration_versions`, emit audit log in `wallet-ecosystem/services/api/app/Modules/Platform/Application/Services/ConfigurationChangeRequestService.php`
- [x] T024 [US5] Implement `ConfigurationChangeRequestService::approve()` — open DB transaction, `lockForUpdate()` on change request row, assert `status == pending_approval` (throw ConflictException if not), assert `checker_id != maker_id` (throw ForbiddenException if same), write `ConfigurationApprovalModel` record, write `approved` version record, write `draft` row to `platform.configuration_values` in `wallet-ecosystem/services/api/app/Modules/Platform/Application/Services/ConfigurationChangeRequestService.php`
- [x] T025 [US5] Implement `ConfigurationChangeRequestService::reject()` — open DB transaction, `lockForUpdate()` on request, assert `status == pending_approval` (409 if not), write rejection approval record, write `rejected` version record, dispatch maker notification via E17 NotificationService in `wallet-ecosystem/services/api/app/Modules/Platform/Application/Services/ConfigurationChangeRequestService.php`
- [x] T026 [US5] Implement `ConfigurationChangeRequestService::withdraw()` — assert `caller_id == maker_id` (403 if not), assert `status == pending_approval` (409 if not), write `withdrawn` version record, transition request to `withdrawn` in `wallet-ecosystem/services/api/app/Modules/Platform/Application/Services/ConfigurationChangeRequestService.php`
- [x] T027 [US5] Extend `ConfigurationActivationJob::handle()` to write a `VersionEventType::Activated` record to `platform.configuration_versions` after each `draft → active` transition, correlating via config_key lookup on the change request in `wallet-ecosystem/services/api/app/Jobs/ConfigurationActivationJob.php`
- [x] T028 [US5] Add activation-time domain re-validation in `ConfigurationActivationJob` — if re-validation fails, transition change request to `failed` status, write `failed` version record, dispatch maker notification via E17 NotificationService in `wallet-ecosystem/services/api/app/Jobs/ConfigurationActivationJob.php`
- [x] T029 [P] [US5] Create `SubmitChangeRequestRequest` form request with `configuration.write` authorization and validation rules (config_key required, proposed_value required) in `wallet-ecosystem/services/api/app/Modules/Platform/Http/Requests/SubmitChangeRequestRequest.php`
- [x] T030 [P] [US5] Create `ApproveChangeRequestRequest` form request with `configuration.approve` authorization in `wallet-ecosystem/services/api/app/Modules/Platform/Http/Requests/ApproveChangeRequestRequest.php`
- [x] T031 [P] [US5] Create `RejectChangeRequestRequest` form request with `reason` required validation in `wallet-ecosystem/services/api/app/Modules/Platform/Http/Requests/RejectChangeRequestRequest.php`
- [x] T032 [P] [US5] Create `WithdrawChangeRequestRequest` form request with `configuration.write` authorization and `Idempotency-Key` header validation (required, unique; prevents duplicate withdrawal submissions per P8) in `wallet-ecosystem/services/api/app/Modules/Platform/Http/Requests/WithdrawChangeRequestRequest.php`
- [x] T033 [US5] Implement `ConfigurationChangeRequestAdminController` with `index`, `store`, `show`, `approve`, `reject`, `withdraw` methods delegating to `ConfigurationMakerCheckerService` in `wallet-ecosystem/services/api/app/Modules/Platform/Http/Controllers/Admin/ConfigurationChangeRequestController.php`
- [x] T034 [US5] Write feature tests `ConfigurationChangeRequestApiTest` covering: SC-001 (change not active until approved), SC-002 (approve then poll `ConfigurationService::get()` in a loop asserting active within 60s), FR-006 (self-approval returns 403), FR-007 (duplicate pending returns 409), FR-008 (maker withdrawal returns 200 and config unchanged), FR-009 (concurrent approve+approve: first 200, second 409), FR-019 (unauthenticated request returns 401) in `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/ConfigurationChangeRequestApiTest.php`
- [x] T035 [US5] Write `ConfigurationConcurrencyTest` using two parallel DB transactions to fire simultaneous approve requests for SC-006 — assert exactly one succeeds and exactly one `activated` version record is created in `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/ConfigurationConcurrencyTest.php`

**Checkpoint**: Full maker-checker workflow functional — submit/approve/reject/withdraw all work, concurrent race handled, activation hook live; subsequent domain story phases can build on this foundation.

---

## Phase 4: User Story 1 — Financial Policy Administration (Priority: P1)

**Goal**: Financial policy configuration keys (transaction limits, wallet limits, fees, commissions) can be submitted and validated via the workflow with domain-specific schema validation.

**Independent Test**: Submit change to `financial_policy.max_single_transfer` with value `-100` → 400; submit with `"not_a_number"` → 400; submit valid integer value → 201 pending_approval; approve → activated within 60s.

- [x] T036 [US1] Implement `FinancialPolicyConfigValidator` with guards: positive decimal/integer for limit keys, 0–100 range for fee/commission rate keys, valid ISO 4217 currency code where required in `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Validators/FinancialPolicyConfigValidator.php`
- [x] T037 [P] [US1] Write unit tests `FinancialPolicyConfigValidatorTest` — valid positive limit, negative limit rejected, zero rejected, non-numeric rejected, valid fee rate, out-of-range fee rate rejected in `wallet-ecosystem/services/api/tests/Unit/Modules/Platform/FinancialPolicyConfigValidatorTest.php`
- [x] T038 [US1] Wire all three domain validators into `ConfigurationChangeRequestService` using a tagged service-container binding resolving the correct validator by `ConfigurationDomain` — register bindings in `wallet-ecosystem/services/api/app/Modules/Platform/Providers/PlatformServiceProvider.php`
- [x] T039 [US1] Write feature test for SC-005 financial_policy: invalid type, negative value, and out-of-range fee all return 400 with descriptive errors; valid submission returns 201 in `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/ConfigurationChangeRequestApiTest.php`
- [x] T040 [P] [US1] Implement admin portal domain list page with financial_policy tab, key table showing key name, current value, has_pending_change badge, last_modified_at in `wallet-ecosystem/apps/admin/app/(admin)/configuration/page.tsx`

**Checkpoint**: Financial policy changes validate, submit, and activate correctly via the workflow.

---

## Phase 5: User Story 4 — Configuration Version History (Priority: P1)

**Goal**: Immutable version history queryable for any config key; admin portal pages show the history and enable approval/rejection actions.

**Independent Test**: Make 3 successive changes to the same key; retrieve history; all 3 events present with correct event_type, maker_id, checker_id, effective_at; attempt `DELETE FROM platform.configuration_versions` → permission denied.

- [x] T041 [US4] Implement `ConfigurationVersionAdminController::index()` — paginated history for a config key, accepting `from`, `to`, `maker_id`, `checker_id` query params; gated on `configuration.read` in `wallet-ecosystem/services/api/app/Modules/Platform/Http/Controllers/Admin/ConfigurationVersionAdminController.php`
- [x] T042 [US4] Write feature tests `ConfigurationVersionHistoryApiTest` covering: SC-003 (all 6 event types produce records), SC-004 (100-record query under 2s), FR-016 (records retained; no purge), FR-017 (pagination, date-range filter, maker filter) in `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/ConfigurationVersionHistoryApiTest.php`
- [x] T043 [US4] Implement key detail page showing current value, domain, pending change link, and paginated version history table (event_type, previous→new value diff, maker name, checker name, effective_at) in `wallet-ecosystem/apps/admin/app/(admin)/configuration/[domain]/[key]/page.tsx`
- [x] T044 [P] [US4] Implement pending approvals queue page listing all `pending_approval` requests with config_key, domain, maker, submitted_at, age indicator, and link to detail in `wallet-ecosystem/apps/admin/app/(admin)/configuration/approvals/page.tsx`
- [x] T045 [P] [US4] Implement change request detail page showing proposed vs previous value diff, current status badge, and version trail in `wallet-ecosystem/apps/admin/app/(admin)/configuration/approvals/[id]/page.tsx`
- [x] T046 [US4] Add approve, reject (with required reason input), and withdraw action buttons with confirmation dialogs to the change request detail page in `wallet-ecosystem/apps/admin/app/(admin)/configuration/approvals/[id]/page.tsx`

**Checkpoint**: Version history API and admin portal UI functional; compliance officer can reconstruct complete change history for SC-007.

---

## Phase 6: User Story 2 — Operational Policy Administration (Priority: P1)

**Goal**: Operational policy configuration (KYC rules, risk scoring rules, notification templates) validated at submission with domain-specific checks including template placeholder detection.

**Independent Test**: Submit notification template change missing `{{otp_code}}` → 400; submit valid KYC rule change → approve → active within 60s; while pending, verify platform still uses previous active rule.

- [x] T047 [US2] Implement `OperationalPolicyConfigValidator` with: KYC rule struct shape validation, risk weight sum validation (weights must sum to 1.0 ± 0.001), notification template placeholder detection (reject if required `{{variable}}` tokens are absent) in `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Validators/OperationalPolicyConfigValidator.php`
- [x] T048 [P] [US2] Write unit tests `OperationalPolicyConfigValidatorTest` — valid KYC struct, malformed struct rejected, risk weights sum to 1.0, weights not summing to 1.0 rejected, template with required placeholder, template missing placeholder rejected in `wallet-ecosystem/services/api/tests/Unit/Modules/Platform/OperationalPolicyConfigValidatorTest.php`
- [x] T049 [US2] Write feature test for SC-005 operational_policy: missing template placeholder returns 400, invalid risk weight sum returns 400, valid submission returns 201 in `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/ConfigurationChangeRequestApiTest.php`
- [x] T050 [US2] Write feature test for US2/AC2: submit a KYC rule change; while it is `pending_approval`, query the config via `ConfigurationService::get()`; assert it returns the previously active rule, not the proposed value in `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/ConfigurationChangeRequestApiTest.php`

**Checkpoint**: Operational policy changes (KYC, risk rules, notification templates) submit and validate via the workflow.

---

## Phase 7: User Story 3 — Geographic Configuration Administration (Priority: P2)

**Goal**: Geographic configuration (supported currencies, supported countries) validated for ISO code correctness and duplicates; deactivating a country does not affect existing customers.

**Independent Test**: Submit new currency with invalid ISO code → 400; submit duplicate ISO code → 400; submit valid BWP currency → approve → active within 60s.

- [x] T051 [US3] Implement `GeographicConfigValidator` with: ISO 4217 currency code validation, ISO 3166-1 alpha-2 country code validation, duplicate active code detection in `wallet-ecosystem/services/api/app/Modules/Platform/Domain/Validators/GeographicConfigValidator.php`
- [x] T052 [P] [US3] Write unit tests `GeographicConfigValidatorTest` — valid BWP code, invalid code rejected, valid ZW country code, invalid country code rejected, duplicate active currency rejected in `wallet-ecosystem/services/api/tests/Unit/Modules/Platform/GeographicConfigValidatorTest.php`
- [x] T053 [US3] Write feature test for SC-005 geographic domain: invalid ISO currency code returns 400, duplicate active code returns 400, valid new currency returns 201 in `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/ConfigurationChangeRequestApiTest.php`
- [x] T054 [US3] Write feature test for US3/AC3: deactivate a supported country via approved change; verify new onboarding from that country is rejected; verify existing customers from that country are unaffected in `wallet-ecosystem/services/api/tests/Feature/Modules/Platform/ConfigurationChangeRequestApiTest.php`

**Checkpoint**: Geographic configuration changes (currencies, countries) validate and submit via the workflow.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Escalation command, seed command, OpenAPI documentation, PHPStan, accessibility, and remaining integration tests.

- [x] T055 Create `ConfigurationEscalationCheckCommand` (`configuration:escalation-check`) querying `pending_approval` requests older than `configuration.escalation_threshold_hours` and emitting audit event with action `configuration.change_request.escalated` via `AuditService::log()` per stale request in `wallet-ecosystem/services/api/app/Console/Commands/ConfigurationEscalationCheckCommand.php`
- [x] T056 Create `ConfigurationSeedDomainsCommand` (`configuration:seed-domains`) backfilling `domain` column on existing `platform.configurations` rows from a hardcoded key-prefix → domain mapping in `wallet-ecosystem/services/api/app/Console/Commands/ConfigurationSeedDomainsCommand.php`
- [x] T057 Register `configuration:escalation-check` as hourly schedule in `wallet-ecosystem/services/api/app/Console/Kernel.php`; invoke `configuration:seed-domains` in the E28 seeder or post-migration hook
- [x] T058 Write unit test `ConfigurationEscalationCheckCommandTest` — seed pending request with `submitted_at` older than threshold and assert audit event emitted; seed fresh request and assert no event in `wallet-ecosystem/services/api/tests/Unit/ConfigurationEscalationCheckCommandTest.php`
- [x] T059 Write integration test `ConfigurationEscalationCheckIntegrationTest` for US5/AC3: seed stale pending request in test DB, run `$this->artisan('configuration:escalation-check')`, assert audit log entry created in `wallet-ecosystem/services/api/tests/Feature/ConfigurationEscalationCheckIntegrationTest.php`
- [x] T060 [P] Write full OpenAPI specification at `wallet-ecosystem/services/api/openapi/v1/configuration.yaml` from `specs/028-configuration-management/contracts/configuration-admin-api.yaml`, adding server URL, security scheme refs, and Laravel envelope details
- [x] T061 [P] Run PHPStan level 8 on all new and modified Platform module PHP classes; resolve all type errors and strict-type violations across new service, repository, controller, and command files
- [x] T062 Accessibility review for all four admin portal configuration pages — keyboard navigation through key table and approval queue, `aria-label` on action buttons, focus management on dialog open/close in `wallet-ecosystem/apps/admin/app/(admin)/configuration/`
- [x] T063 Write feature test verifying FR-004 (no hard-coded values): change `financial_policy.max_single_transfer` via the workflow, approve it, then call the transaction-limit check path and assert it reads the new value from `ConfigurationService::get()` rather than a stale constant — confirms runtime configurability end-to-end in `wallet-ecosystem/services/api/tests/Feature/ConfigurationRuntimeReadTest.php`
- [x] T064 Write feature test for FR-012 restart idempotency: approve a change request, skip `ConfigurationActivationJob` manually (set effective_from in the future to prevent auto-activation), then run `ConfigurationActivationJob` once and assert: (a) configuration is activated, (b) a second manual run of the job produces no additional `Activated` version records (idempotent) in `wallet-ecosystem/services/api/tests/Feature/ConfigurationActivationIdempotencyTest.php`

---

## Phase 9: Convergence

- [x] T065 Write additive migration `2026_10_02_000005_add_fr015_columns_to_configuration_versions` adding `previous_value JSONB NULLABLE`, `maker_id UUID NULLABLE`, `checker_id UUID NULLABLE` to `platform.configuration_versions`; update `ConfigurationChangeRequestService` submit/approve/reject/withdraw methods to populate these columns on each version record insert per FR-015 (`partial`)
- [x] T066 Extend `ConfigurationVersionRepositoryInterface::paginateByKey()` to accept optional filters `?string $from`, `?string $to`, `?string $makerId`, `?string $checkerId`, `?string $domain`; update `EloquentConfigurationVersionRepository` to apply them as query clauses; update `ConfigurationAdminService::getVersionHistory()` and `ConfigurationVersionAdminController` to pass query params through; add feature tests for date-range and maker-id filter assertions per FR-017 (`partial`)
- [x] T067 Write unit tests `ConfigurationChangeRequestServiceTest.php` covering: submit happy path creates change_request + submitted version record, duplicate pending submission returns existing (idempotent), one-pending-per-key throws DuplicateResourceException, approve happy path writes approval + approved version + draft config_value, self-approval throws BusinessRuleViolationException (FR-006), approve non-pending throws BusinessRuleViolationException, reject happy path writes rejected version, withdraw by non-maker throws, withdraw by maker succeeds per plan Phase 3 item 16 (`missing`)
- [x] T068 Write additive migration `2026_10_02_000006_add_missing_indexes_to_configuration_versions` adding `CREATE INDEX idx_config_versions_domain ON platform.configuration_versions (domain, occurred_at DESC)` and `CREATE INDEX idx_config_versions_event_type ON platform.configuration_versions (event_type, occurred_at DESC)` per plan T011 spec (`partial`)
- [x] T069 Add `include_inactive` boolean query param to `ConfigurationAdminService::listKeys()` — when true, include configuration keys whose current value has status `archived` or `inactive` with their last `updated_at` timestamp; update `ConfigurationKeyAdminController::index()` form request and OpenAPI spec per US3/AC5 (`missing`)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately; T002–T004 can run in parallel
- **Foundational (Phase 2)**: Depends on Phase 1 — BLOCKS all user story phases; T012–T017 can run in parallel once T008–T011 are done
- **US5 (Phase 3)**: Depends on Phase 2 — core workflow; BLOCKS US1 validator wiring (T038)
- **US1 (Phase 4)**: Depends on Phase 3 (T038 validator wiring needs service in place); T040 can run in parallel with T036–T039
- **US4 (Phase 5)**: Depends on Phase 3 (needs version records created by workflow); can run in parallel with US1 Phase 4
- **US2 (Phase 6)**: Depends on Phase 3 + T038 from US1 (validator wiring); can run in parallel with US4
- **US3 (Phase 7)**: Depends on Phase 3 + T038 from US1; can run in parallel with US2 and US4
- **Polish (Phase 8)**: Depends on all user story phases complete; T060–T061 can run in parallel

### User Story Dependencies

- **US5 (Phase 3)**: Depends on Foundational only
- **US1 (Phase 4)**: Depends on US5 + T038 validator wiring
- **US4 (Phase 5)**: Depends on US5 (version records generated by workflow)
- **US2 (Phase 6)**: Depends on US5 + T038
- **US3 (Phase 7)**: Depends on US5 + T038

### Within Each Phase

- DTOs (T019–T022) before service methods (T023–T026)
- Service methods before form requests (T029–T032)
- Form requests before controller (T033)
- Controller before feature tests (T034–T035)
- Validator (T036) before validator unit tests (T037)

---

## Parallel Execution Examples

### Phase 1 — enums all parallelizable
```
T002 ConfigurationDomain enum
T003 ChangeRequestStatus enum
T004 VersionEventType enum
```

### Phase 2 — models and repositories parallelizable (after migrations)
```
T012 ConfigurationChangeRequestModel
T013 ConfigurationVersionModel
T014 ConfigurationApprovalModel

T015 ChangeRequestRepository
T016 ConfigurationVersionRepository
T017 ConfigurationApprovalRepository
```

### Phase 3 (US5) — DTOs parallelizable
```
T019 SubmitChangeRequestDTO
T020 ApproveChangeRequestDTO
T021 RejectChangeRequestDTO
T022 WithdrawChangeRequestDTO
```

### Phase 8 (Polish) — all three can run in parallel
```
T060 OpenAPI documentation
T061 PHPStan level 8
```

---

## Implementation Strategy

### MVP First (US5 + US1)

1. Complete Phase 1: Setup (T001–T007)
2. Complete Phase 2: Foundational (T008–T018)
3. Complete Phase 3: US5 Maker-Checker Workflow (T019–T035)
4. Complete Phase 4: US1 Financial Policy (T036–T040)
5. **STOP and VALIDATE**: Run `quickstart.md` SC-001, SC-002, SC-005, SC-006 scenarios
6. Financial policy changes flow end-to-end with maker-checker ✓

### Incremental Delivery

1. Setup + Foundational → DB ready
2. US5 → Core workflow live (all domains)
3. US1 → Financial policy validated end-to-end (MVP ✓)
4. US4 → Version history API + admin portal
5. US2 → Operational policy validated
6. US3 → Geographic config validated
7. Polish → PHPStan, OpenAPI docs, accessibility
