# Tasks: E23 — Audit & Compliance

**Input**: Design documents from `specs/023-audit-compliance/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/ ✓, quickstart.md ✓

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks in this phase)
- **[Story]**: Which user story this task belongs to (US1–US5)
- All paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup

**Purpose**: Module scaffold and infrastructure registration — required before any story begins.

- [X] T001 Create `app/Modules/Audit/AuditServiceProvider.php` — registers `AuditModuleContract`, event listeners, and route file
- [X] T002 [P] Create `config/audit.php` — keys: `audit.hmac_key` (env `AUDIT_HMAC_KEY`), `audit.per_page` (default 20), `audit.report.async_threshold_days` (default 1)
- [X] T003 [P] Create `routes/audit.php` — empty route file with `Route::prefix('v1')->middleware(['auth:sanctum'])` grouping; include from RouteServiceProvider
- [X] T004 Create `database/migrations/2026_09_28_000001_create_audit_schema.php` — `CREATE SCHEMA IF NOT EXISTS audit`; uses `platform` connection
- [X] T005 Register `AuditServiceProvider::class` in `bootstrap/providers.php`

**Checkpoint**: Module registered, schema created, config and routes in place.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: `AuditModuleContract`, `AuditEntry`, `AuditEventModel` with immutability and integrity hash, and `AuditService::record()` — MUST be complete before any user story can record or query audit events.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T006 Create `database/migrations/2026_09_28_000002_create_audit_events_table.php` — table `audit.audit_events` per data-model.md; `id` column MUST use UUID v7 (Constitution Principle 8): `$table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'))` or generate in `AuditEventModel::boot()` `creating` hook via `UuidV7::generate()` — same pattern as E06/E07; include `BEFORE UPDATE` and `BEFORE DELETE` PL/pgSQL triggers that `RAISE EXCEPTION 'audit_immutable'`; uses `platform` connection
- [X] T007 [P] Create `database/migrations/2026_09_28_000003_create_audit_events_archive_table.php` — mirror schema of `audit_events` plus `archived_at TIMESTAMPTZ NOT NULL DEFAULT now()`; same immutability triggers
- [X] T008 [P] Create `app/Modules/Audit/Domain/Enums/AuditEventCategory.php` — backed enum (string): `Authentication`, `Authorization`, `Wallet`, `Transaction`, `Kyc`, `Admin`, `Config`, `Security`
- [X] T009 [P] Create `app/Modules/Audit/Domain/Enums/AuditActorType.php` — backed enum (string): `Customer`, `Agent`, `Admin`, `System`
- [X] T010 [P] Create `app/Modules/Audit/Domain/Enums/AuditOutcome.php` — backed enum (string): `Success`, `Failure`, `Denied`
- [X] T011 [P] Create `app/Modules/Audit/Domain/Exceptions/AuditImmutabilityViolationException.php` — HTTP 403, code `AUDIT_IMMUTABLE`
- [X] T012 [P] Create `app/Modules/Audit/Domain/Exceptions/AuditWriteException.php` — HTTP 500, code `AUDIT_WRITE_FAILED`
- [X] T013 Create `app/Modules/Audit/Domain/ValueObjects/AuditEntry.php` — `final readonly class`; fields: `category`, `eventType`, `actorType`, `action`, `outcome`, `correlationId`, `actorId`, `entityType`, `entityId`, `beforeState`, `afterState`, `metadata`, `occurredAt`; fluent `with*()` builder methods per `contracts/AuditEntry.php`
- [X] T014 Create `app/Modules/Audit/Infrastructure/Models/AuditEventModel.php` — `$connection = 'platform'`, `$table = 'audit.audit_events'`; `boot()` registers `creating` hook to compute HMAC-SHA256 integrity hash (canonical fields: `id|event_category|event_type|actor_id|entity_type|entity_id|outcome|occurred_at|correlation_id`); `updating` and `deleting` hooks throw `AuditImmutabilityViolationException`; `verifyIntegrity(): bool` method re-computes and compares hash
- [X] T015 Create `app/Modules/Audit/Domain/Contracts/AuditModuleContract.php` — interface with `record(AuditEntry $entry): string`; docblock per `contracts/AuditModuleContract.php`
- [X] T016 Create `app/Modules/Audit/Application/Services/AuditService.php` — implements `AuditModuleContract`; `record()`: builds `AuditEventModel` from `AuditEntry`, saves within current DB transaction context (uses `DB::connection('platform')`), returns the UUID; publishes `AuditEventRecordedEvent` via `OutboxEventPublisher` post-commit; throws `AuditWriteException` on failure
- [X] T017 Bind `AuditModuleContract::class` → `AuditService::class` in `AuditServiceProvider::register()`
- [X] T018 Unit test `tests/Unit/Modules/Audit/AuditEntryTest.php` — AuditEntry construction, withActor/withEntity/withMetadata/withOccurredAt builder methods
- [X] T019 Unit test `tests/Unit/Modules/Audit/AuditServiceTest.php` — `record()` persists correct fields; integrity_hash is non-null; `record()` within an explicit transaction; `AuditImmutabilityViolationException` thrown on model update/delete attempt

**Checkpoint**: `AuditModuleContract::record()` is functional. All other modules can now inject and call it.

---

## Phase 3: User Story 1 — Record Platform Events (Priority: P1) 🎯 MVP

**Goal**: Expose a read API for audit events so compliance officers can query the immutable audit trail produced by all platform modules calling `AuditModuleContract::record()`.

**Independent Test**: Trigger a login (US-AUDIT-001) to produce an audit event, then call `GET /v1/audit/events?category=authentication` and verify the event appears with correct fields.

**⚠️ Cross-epic prerequisite (SC-001)**: US1 exposes the read API only; the write path for each event category is owned by its source module (E01–E22 must call `AuditModuleContract::record()` for their events). Quickstart SC-1 through SC-8 will not produce events until the relevant source modules are updated. Confirm E01 calls `AuditModuleContract::record()` for authentication events before running quickstart SC-1. Track per-epic instrumentation outside this tasks list (see Notes section).

- [X] T020 [P] [US1] Create `app/Modules/Audit/Infrastructure/Http/Requests/ListAuditEventsRequest.php` — validated query params: `category` (AuditEventCategory enum), `actor_id` (uuid), `entity_type` (string), `entity_id` (uuid), `outcome` (AuditOutcome enum), `correlation_id` (uuid), `from` (ISO datetime), `to` (ISO datetime), `cursor` (string), `per_page` (integer 1–100, default config `audit.per_page`)
- [X] T021 [P] [US1] Create `app/Modules/Audit/Infrastructure/Http/Resources/AuditEventSummaryResource.php` — fields: `id`, `event_category`, `event_type`, `actor_id`, `actor_type`, `entity_type`, `entity_id`, `action`, `outcome`, `correlation_id`, `occurred_at`
- [X] T022 [P] [US1] Create `app/Modules/Audit/Infrastructure/Http/Resources/AuditEventDetailResource.php` — extends summary with `before_state`, `after_state`, `metadata`, `integrity_verified` (calls `model->verifyIntegrity()`)
- [X] T023 [US1] Implement `app/Modules/Audit/Infrastructure/Http/Controllers/AuditEventListController.php` — `__invoke(ListAuditEventsRequest $request)`: cursor-paginated query on `audit_events` (keyset on `occurred_at DESC, id DESC`); base64-encode cursor; enforce `audit.read` permission via `$this->authorize` or policy; returns `AuditEventSummaryResource` collection
- [X] T024 [US1] Implement `app/Modules/Audit/Infrastructure/Http/Controllers/AuditEventDetailController.php` — `__invoke(string $id)`: find by UUID or 404; enforce `audit.read`; returns `AuditEventDetailResource`
- [X] T025 [US1] Register audit event routes in `routes/audit.php`:
  - `GET /v1/audit/events` → `AuditEventListController`
  - `GET /v1/audit/events/{id}` → `AuditEventDetailController`
  - Both wrapped in `middleware(['permission:audit.read'])`
- [X] T026 [P] [US1] Create `app/Modules/Audit/Domain/Events/AuditEventRecordedEvent.php` — `final readonly class implements IntegrationEventInterface`; fields: `auditEventId`, `eventCategory`, `eventType`, `actorId`, `occurredAt`, `correlationId`
- [X] T027 [US1] Publish `AuditEventRecordedEvent` via `OutboxEventPublisher::publish()` inside `AuditService::record()` after the model is saved (within the same DB transaction)
- [X] T028 [US1] Feature test `tests/Feature/Modules/Audit/AuditEventListTest.php` — list all events (200); filter by `category`, `actor_id`, `correlation_id`; cursor pagination (second page returns different events); verify `AuditEventSummaryResource` fields present; **SC-001 coverage**: seed one `AuditEventModel` record for each of the 8 categories (authentication, authorization, wallet, transaction, kyc, admin, config, security) directly via `AuditService::record()` and assert all 8 are retrievable via the list endpoint within the same test transaction
- [X] T029 [US1] Feature test `tests/Feature/Modules/Audit/AuditEventDetailTest.php` — get existing event returns `integrity_verified=true`; get non-existent ID returns 404; actor without `audit.read` returns 403
- [X] T030 [US1] Feature test `tests/Feature/Modules/Audit/AuditPermissionTest.php` — customer token on `GET /v1/audit/events` returns 403 with `FORBIDDEN` error code

**Checkpoint**: Compliance officers can query the full audit trail. US1 is independently testable.

---

## Phase 4: User Story 2 — Enforce Audit Immutability (Priority: P2)

**Goal**: Prove and document the immutability guarantee: any attempt to modify or delete an audit event is blocked and itself recorded as a security event.

**Independent Test**: Call `AuditEventModel->save()` on an existing event in a unit test and confirm `AuditImmutabilityViolationException`; then verify the exception handler records a security event via `AuditModuleContract::record()` with `event_category=security` and `event_type=security.tamper.attempt`.

- [X] T031 [US2] Update `AuditEventModel::boot()` to inject `AuditModuleContract` via `app(AuditModuleContract::class)` in the `updating` and `deleting` hooks — before throwing `AuditImmutabilityViolationException`, call `record(AuditEntry)` with category `Security`, event type `security.tamper.attempt`, actor from the hook context, outcome `Denied`
- [X] T032 [US2] Unit test `tests/Unit/Modules/Audit/AuditImmutabilityTest.php` — `AuditEventModel::save()` on existing record throws `AuditImmutabilityViolationException`; verify `AuditEventModel::delete()` throws same; verify a second audit event with `event_type=security.tamper.attempt` was recorded
- [X] T033 [US2] Unit test `tests/Unit/Modules/Audit/IntegrityHashTest.php` — `verifyIntegrity()` returns true for an unmodified record; returns false when `integrity_hash` is manually altered; hash uses `AUDIT_HMAC_KEY` from config
- [X] T034 [US2] Feature test `tests/Feature/Modules/Audit/AuditImmutabilityFeatureTest.php` — verify no `PATCH` route exists for `/v1/audit/events/{id}` (405); verify no `DELETE` route exists (405); verify DB trigger prevents direct SQL update by asserting exception when trying raw `DB::statement('UPDATE audit.audit_events ...')`
- [X] T035 [US2] Feature test: retrieve an event via `GET /v1/audit/events/{id}` and assert `integrity_verified=true` in response body

**Checkpoint**: Immutability is fully enforced at application and DB layer. US2 is independently testable.

---

## Phase 5: User Story 3 — Maintain Compliance Status & History (Priority: P3)

**Goal**: Maintain a per-user compliance snapshot (KYC tier + AML risk level + restrictions) updated reactively from E05/E18 events; expose current status and full history to compliance officers.

**Independent Test**: Seed a user with KYC tier=1, query `GET /v1/compliance/status/{userId}` and confirm `kyc_tier=1`; change tier to 2 via E05 event simulation, query history and confirm the change appears as an immutable entry.

- [X] T036 Create `database/migrations/2026_09_28_000004_create_compliance_status_table.php` — `audit.compliance_status` per data-model.md; unique index on `user_id`; uses `platform` connection
- [X] T037 Create `database/migrations/2026_09_28_000005_create_compliance_history_table.php` — `audit.compliance_history` per data-model.md; add `out_of_order BOOLEAN NOT NULL DEFAULT FALSE` column (flags entries whose `changed_at` is earlier than the most recent existing entry for the same user — spec edge case); include `BEFORE UPDATE/DELETE` triggers (append-only same as audit_events); index on `(user_id, changed_at DESC)`
- [X] T038 [P] [US3] Create `app/Modules/Audit/Infrastructure/Models/ComplianceStatusModel.php` — `$connection = 'platform'`, `$table = 'audit.compliance_status'`; casts for `active_restrictions` (array), `version` (integer); optimistic lock guard in `save()` using `version`
- [X] T039 [P] [US3] Create `app/Modules/Audit/Infrastructure/Models/ComplianceHistoryModel.php` — `$connection = 'platform'`, `$table = 'audit.compliance_history'`; `boot()` registers `updating`/`deleting` hooks that throw `AuditImmutabilityViolationException`
- [X] T040 [P] [US3] Create `app/Modules/Audit/Domain/Exceptions/ComplianceStatusNotFoundException.php` — HTTP 404, code `COMPLIANCE_STATUS_NOT_FOUND`
- [X] T041 [US3] Implement `app/Modules/Audit/Application/Services/ComplianceStatusService.php`:
  - `getStatus(string $userId): ComplianceStatusModel` — finds by user_id or throws `ComplianceStatusNotFoundException`
  - `getHistory(string $userId, ?string $cursor, int $perPage): LengthAwarePaginator` — cursor-paginated query on `compliance_history` keyed on `(changed_at DESC, id DESC)`
  - `getTransactions(array $filters, ?string $cursor, int $perPage)` — cursor-paginated query exclusively via `TransactionModuleContract` (Constitution Principle 7: no direct cross-schema joins); returns fields per `ComplianceTransactionItem` schema in `contracts/openapi.yaml`; accepted filters: `user_id`, `from`, `to`, `transaction_type`, `outcome`
  - `applyKycChange(string $userId, int $newTier, string $auditEventId, string $correlationId, CarbonImmutable $occurredAt): void` — compare `$occurredAt` against `MAX(changed_at)` for this user in `compliance_history`; set `out_of_order = true` on the new history entry if `$occurredAt < MAX(changed_at)`; only overwrite `compliance_status` if NOT out-of-order (do not regress a newer status with a stale event); append `compliance_history` entry unconditionally; emit `ComplianceStatusChangedEvent`
  - `applyAmlChange(string $userId, string $newRiskLevel, string $auditEventId, string $correlationId, CarbonImmutable $occurredAt): void` — same out-of-order detection and conditional upsert pattern as `applyKycChange`
  - `applyRestrictionChange(string $userId, array $restrictions, string $auditEventId, string $correlationId, CarbonImmutable $occurredAt): void` — same out-of-order detection pattern as `applyKycChange`; only update `active_restrictions` in `compliance_status` if NOT out-of-order; append `compliance_history` entry unconditionally with `out_of_order` flag set appropriately
- [X] T042 [P] [US3] Create `app/Modules/Audit/Infrastructure/Listeners/KycStatusChangedListener.php` — **before writing**: grep E05 source for the exact class name, namespace, and public fields of `KycStatusChangedEvent` (expected: `App\Modules\Kyc\Domain\Events\KycStatusChangedEvent` with fields `userId`, `newTier`, `previousTier`, `auditEventId`, `correlationId`) and confirm they match; listens to that event; calls `ComplianceStatusService::applyKycChange()` with verified field names; wrapped in `DB::connection('platform')->transaction()`
- [X] T043 [P] [US3] Create `app/Modules/Audit/Infrastructure/Listeners/AmlRiskLevelChangedListener.php` — **before writing**: grep E18 source for the exact class name, namespace, and fields of `AmlRiskLevelChangedEvent` (expected: `App\Modules\AmlRisk\Domain\Events\AmlRiskLevelChangedEvent` with fields `userId`, `newRiskLevel`, `previousRiskLevel`, `auditEventId`, `correlationId`) and confirm; listens to that event; calls `ComplianceStatusService::applyAmlChange()` with verified field names
- [X] T044 [P] [US3] Create `app/Modules/Audit/Infrastructure/Listeners/AmlRestrictionChangedListener.php` — **before writing**: grep E18 source for exact class names, fields, and the event timestamp field of `AmlRestrictionAppliedEvent` and `AmlRestrictionLiftedEvent`; listens to those events; calls `ComplianceStatusService::applyRestrictionChange()` passing the verified event timestamp as `$occurredAt`
- [X] T045 [US3] Register all three listeners in `AuditServiceProvider::boot()` via `Event::listen()`
- [X] T046 [P] [US3] Create `app/Modules/Audit/Domain/Events/ComplianceStatusChangedEvent.php` — `final readonly class implements IntegrationEventInterface`; fields: `userId`, `fieldChanged`, `beforeValue`, `afterValue`, `occurredAt`, `correlationId`
- [X] T047 [US3] Implement `app/Modules/Audit/Infrastructure/Http/Controllers/ComplianceStatusController.php` — `__invoke(string $userId)`: enforce `compliance.read`; return `ComplianceStatusResource`
- [X] T048 [US3] Implement `app/Modules/Audit/Infrastructure/Http/Controllers/ComplianceHistoryController.php` — `__invoke(string $userId, Request $request)`: enforce `compliance.read`; cursor-paginated; return `ComplianceHistoryResource` collection
- [X] T049 [US3] Implement `app/Modules/Audit/Infrastructure/Http/Controllers/ComplianceTransactionController.php` — `__invoke(Request $request)`: enforce `compliance.read`; accepts `user_id`, `from`, `to`, `transaction_type`, `outcome`, `cursor`, `per_page` query params; return `ComplianceTransactionResource` collection
- [X] T050 [P] [US3] Create `app/Modules/Audit/Infrastructure/Http/Resources/ComplianceStatusResource.php` — fields: `user_id`, `kyc_tier`, `aml_risk_level`, `active_restrictions`, `last_changed_at`
- [X] T051 [P] [US3] Create `app/Modules/Audit/Infrastructure/Http/Resources/ComplianceHistoryResource.php` — fields per data-model; cursor pagination wrapper
- [X] T052 [P] [US3] Create `app/Modules/Audit/Infrastructure/Http/Resources/ComplianceTransactionResource.php` — fields: `transaction_id`, `transaction_type`, `amount_minor`, `currency_code`, `outcome`, `occurred_at`, `user_id`
- [X] T053 [US3] Register compliance status/history/transaction routes in `routes/audit.php`:
  - `GET /v1/compliance/status/{userId}` → `ComplianceStatusController`
  - `GET /v1/compliance/history/{userId}` → `ComplianceHistoryController`
  - `GET /v1/compliance/transactions` → `ComplianceTransactionController`
  - All wrapped in `middleware(['permission:compliance.read'])`
- [X] T054 [US3] Feature test `tests/Feature/Modules/Audit/ComplianceStatusTest.php` — getStatus returns correct kyc_tier and aml_risk_level; 404 for unknown user; 403 for missing `compliance.read`; **SC-003 response-time gate**: assert response time <300ms using `$start = microtime(true)` before the HTTP call and `$this->assertLessThan(0.3, microtime(true) - $start)` after the response is received
- [X] T055 [US3] Feature test `tests/Feature/Modules/Audit/ComplianceHistoryTest.php` — **SC-004 completeness**: seed exactly 3 distinct status changes for the same user (KYC change → AML risk change → restriction change) via `AuditServiceProvider` listener simulation; assert history response contains exactly 3 entries (count = 3, not fewer) in descending `changed_at` order, each with correct `field_changed`, `before_value`, and `after_value`; test pagination: split with `per_page=2` and assert page 1 has 2 entries and page 2 has 1 entry via cursor

**Checkpoint**: Compliance officers can query per-user compliance status and history. US3 independently testable.

---

## Phase 6: User Story 4 — Generate Compliance Reports and SARs (Priority: P4)

**Goal**: Let compliance officers generate compliance summary reports and suspicious activity reports; both are idempotent on `Idempotency-Key`.

**Independent Test**: POST `/v1/compliance/reports` with `compliance_summary` type, poll until `status=completed`, verify `report_data` is non-null; POST `/v1/compliance/sars` for a test user and verify `report_number` follows `SAR-YYYYMMDD-NNNNNN` format.

- [X] T056 Create `database/migrations/2026_09_28_000006_create_compliance_reports_table.php` — `audit.compliance_reports` per data-model.md; unique index on `idempotency_key`; uses `platform` connection; **omit `report_file_reference` column** — file export is out of scope for E23 (US4 delivers JSON `report_data` only; no download endpoint exists); add the column only if a future epic introduces file export
- [X] T057 Create `database/migrations/2026_09_28_000007_create_suspicious_activity_reports_table.php` — `audit.suspicious_activity_reports` per data-model.md; unique index on `idempotency_key` and `report_number`; uses `platform` connection
- [X] T058 [P] [US4] Create `app/Modules/Audit/Infrastructure/Models/ComplianceReportModel.php` — `$connection = 'platform'`, `$table = 'audit.compliance_reports'`; casts for `filters` and `report_data` (array), `status` (enum)
- [X] T059 [P] [US4] Create `app/Modules/Audit/Infrastructure/Models/SuspiciousActivityReportModel.php` — `$connection = 'platform'`, `$table = 'audit.suspicious_activity_reports'`; casts for JSONB columns; `report_number` auto-generated on creating: `SAR-{Ymd}-{6-digit padded sequence}` (use DB sequence or atomic counter)
- [X] T060 [P] [US4] Create `app/Modules/Audit/Domain/Exceptions/ComplianceReportNotFoundException.php` — HTTP 404, code `COMPLIANCE_REPORT_NOT_FOUND`
- [X] T061 [P] [US4] Create `app/Modules/Audit/Domain/Exceptions/SARNotFoundException.php` — HTTP 404, code `SAR_NOT_FOUND`
- [X] T062 [US4] Implement `app/Modules/Audit/Application/Services/ComplianceReportService.php`:
  - `requestReport(string $reportType, string $dateRangeStart, string $dateRangeEnd, array $filters, string $idempotencyKey, string $actorId, string $correlationId): ComplianceReportModel` — idempotent (find by idempotency_key); create record with status=pending; for date range ≤`audit.report.async_threshold_days`, generate inline and set status=completed; for larger ranges, dispatch `GenerateComplianceReportJob`; return model
  - `getReport(string $reportId): ComplianceReportModel` — find or throw `ComplianceReportNotFoundException`
- [X] T063 [US4] Implement `app/Modules/Audit/Infrastructure/Jobs/GenerateComplianceReportJob.php` — queued job; aggregates `audit_events` counts by category, `compliance_status` distribution, and transaction statistics for date range; updates `report_data` JSONB and `generated_at`; dispatches `ComplianceReportGeneratedEvent`; **SC-005 async SLA**: job MUST complete within 60 seconds for date ranges up to 30 days — use indexed queries (`occurred_at` index + category index), avoid N+1 patterns, and prefer aggregate SQL over PHP-side iteration; add `tests/Feature/Modules/Audit/ComplianceReportJobTest.php` (Docker-gated): seed 30 days of audit events, dispatch job synchronously via `Bus::dispatchSync()`, assert `status=completed` and wall time <60s
- [X] T064 [US4] Implement `app/Modules/Audit/Application/Services/SuspiciousActivityReportService.php`:
  - `generateSAR(string $subjectUserId, string $reasonCode, string $reasonNarrative, array $flaggedTransactionIds, string $idempotencyKey, string $actorId, string $correlationId): SuspiciousActivityReportModel` — idempotent; collect data from three distinct sources: (1) **`subject_identity_snapshot`** via `IdentityModuleContract::getIdentitySnapshot($subjectUserId)` — Constitution Principle 7, no direct cross-schema access; (2) **`aml_alert_history`** from `audit_events` WHERE `event_category='security' AND entity_type='user' AND entity_id=$subjectUserId` ordered by `occurred_at ASC` — these are security events written by E18 calling `AuditModuleContract::record()`; (3) **`risk_score_timeline`** from `compliance_history` WHERE `user_id=$subjectUserId AND field_changed='aml_risk_level'` ordered by `changed_at ASC` — written by T043 listener; create SAR record; dispatch `SARGeneratedEvent`
  - `getSAR(string $sarId): SuspiciousActivityReportModel` — find or throw `SARNotFoundException`
- [X] T065 [P] [US4] Create `app/Modules/Audit/Domain/Events/ComplianceReportGeneratedEvent.php` — `final readonly class implements IntegrationEventInterface`; fields: `reportId`, `reportType`, `requestedByActorId`, `occurredAt`, `correlationId`
- [X] T066 [P] [US4] Create `app/Modules/Audit/Domain/Events/SARGeneratedEvent.php` — `final readonly class implements IntegrationEventInterface`; fields: `sarId`, `reportNumber`, `subjectUserId`, `createdByActorId`, `occurredAt`, `correlationId`
- [X] T067 [P] [US4] Implement `app/Modules/Audit/Infrastructure/Http/Controllers/ComplianceReportController.php` — `store(GenerateComplianceReportRequest $request)` and `show(string $id)` actions; enforce `compliance.report`; idempotency key from `Idempotency-Key` header (required); 202 for async, 200 for idempotent repeat
- [X] T068 [P] [US4] Implement `app/Modules/Audit/Infrastructure/Http/Controllers/SARController.php` — `store(GenerateSARRequest $request)` and `show(string $id)` actions; enforce `compliance.report`; idempotency key from `Idempotency-Key` header (required); 201 for new, 200 for idempotent repeat
- [X] T069 [P] [US4] Create `app/Modules/Audit/Infrastructure/Http/Requests/GenerateComplianceReportRequest.php` — validates: `report_type` (required, enum: compliance_summary, kyc_snapshot, transaction_volume), `date_range_start` (required, date), `date_range_end` (required, date, after_or_equal:date_range_start), `filters` (nullable, array)
- [X] T070 [P] [US4] Create `app/Modules/Audit/Infrastructure/Http/Requests/GenerateSARRequest.php` — validates: `subject_user_id` (required, uuid), `reason_code` (required, string, max:64), `reason_narrative` (required, string, max:5000), `flagged_transaction_ids` (nullable, array of uuids)
- [X] T071 [P] [US4] Create `app/Modules/Audit/Infrastructure/Http/Resources/ComplianceReportResource.php` — fields per openapi.yaml `ComplianceReportResponse`
- [X] T072 [P] [US4] Create `app/Modules/Audit/Infrastructure/Http/Resources/SARResource.php` — fields per openapi.yaml `SARResponse`
- [X] T073 [US4] Register compliance report and SAR routes in `routes/audit.php`:
  - `POST /v1/compliance/reports` → `ComplianceReportController@store`
  - `GET /v1/compliance/reports/{id}` → `ComplianceReportController@show`
  - `POST /v1/compliance/sars` → `SARController@store`
  - `GET /v1/compliance/sars/{id}` → `SARController@show`
  - All wrapped in `middleware(['permission:compliance.report'])`
- [X] T074 [US4] Feature test `tests/Feature/Modules/Audit/ComplianceReportTest.php` — POST generates report (202 or 200 sync); identical Idempotency-Key on second POST returns same report ID (200); status eventually reaches `completed`; 403 for missing `compliance.report`; **SC-005 sync-path timing gate (sync path only — async path SLA is gated in T063)**: for a date range ≤`audit.report.async_threshold_days` (≤24h), assert `status=completed` is returned in the initial response body (not 202 accepted) and assert total wall time <60s using `$start = microtime(true)` before the POST and `$this->assertLessThan(60.0, microtime(true) - $start)` after asserting the response
- [X] T075 [US4] Feature test `tests/Feature/Modules/Audit/SARTest.php` — POST generates SAR with `report_number` matching `SAR-\d{8}-\d{6}`; assert all required JSONB fields present and non-null in the response: `subject_identity_snapshot` (object), `flagged_transaction_ids` (array, matches input), `aml_alert_history` (array), `risk_score_timeline` (array), `reason_narrative` (non-empty string matching input); identical Idempotency-Key returns same SAR ID with HTTP 200; subject with no AML alert events in `audit_events` produces SAR with `aml_alert_history=[]` and `reason_narrative` containing "no alerts found"

**Checkpoint**: Compliance reports and SARs are generatable and idempotent. US4 independently testable.

---

## Phase 7: User Story 5 — Manage Data Retention Rules (Priority: P5)

**Goal**: Allow authorized admins to define per-category retention rules; a scheduled command enforces them daily — archiving or purging records older than the minimum retention window, never touching records within the window.

**Independent Test**: POST `/v1/compliance/retention-policies` to create a policy with `minimum_retention_days=1`, run `php artisan audit:enforce-retention`, confirm records older than 1 day are archived/purged and records within 1 day are untouched.

- [X] T076 Create `database/migrations/2026_09_28_000008_create_retention_policies_table.php` — `audit.retention_policies` per data-model.md; unique index on `data_category`; uses `platform` connection
- [X] T077 [US5] Create `app/Modules/Audit/Infrastructure/Models/RetentionPolicyModel.php` — `$connection = 'platform'`, `$table = 'audit.retention_policies'`; casts for `effective_from` (date), `enforcement_hold_until` (date, nullable), `minimum_retention_days` (integer)
- [X] T078 [US5] Implement `app/Modules/Audit/Application/Services/RetentionPolicyService.php`:
  - `createPolicy(string $category, int $minDays, string $action, string $actorId, string $correlationId): RetentionPolicyModel` — create record; call `AuditModuleContract::record()` with category=`Config`, eventType=`config.retention_policy.created`; emit `RetentionPolicyChangedEvent`
  - `updatePolicy(string $policyId, int $minDays, string $action, string $actorId, string $correlationId): RetentionPolicyModel` — update record; if `$minDays < $current->minimum_retention_days`, set `enforcement_hold_until = today()->addDay()`; preserve `previous_minimum_retention_days`; audit; emit event
  - `listPolicies(): Collection` — returns all policies
- [X] T079 [P] [US5] Create `app/Modules/Audit/Domain/Events/RetentionPolicyChangedEvent.php` — `final readonly class implements IntegrationEventInterface`; fields: `policyId`, `dataCategory`, `action`, `configuredByActorId`, `occurredAt`, `correlationId`
- [X] T080 [US5] Implement `app/Console/Commands/AuditEnforceRetentionCommand.php` (`php artisan audit:enforce-retention`):
  - For each `RetentionPolicy`: skip if `enforcement_hold_until` is set and `enforcement_hold_until > today()`
  - Cursor-paginated scan of target table for records where `created_at < now()->subDays($policy->minimum_retention_days)`
  - `archive` action: `INSERT INTO audit.audit_events_archive SELECT *, now() FROM audit.audit_events WHERE id = ?` then hard-delete from primary (`DELETE FROM audit.audit_events WHERE id = ?`); for `compliance_history` use the same INSERT-then-DELETE pattern — no `deleted_at` column exists on either table (see Notes section); do NOT use soft-delete
  - `purge` action: `DELETE FROM primary_table WHERE id = ?`
  - After each batch, call `AuditModuleContract::record()` with category=`Admin`, eventType=`admin.retention.enforced`, metadata with counts
  - Emits summary audit event at end of run
- [X] T081 [US5] Register `AuditEnforceRetentionCommand` in `app/Console/Kernel.php` — `->daily()->at('02:00')->timezone('UTC')`; also register in `AuditServiceProvider` console commands list
- [X] T082 [US5] Implement `app/Modules/Audit/Infrastructure/Http/Controllers/RetentionPolicyController.php` — `index()`, `store(RetentionPolicyRequest $request)`, `update(RetentionPolicyRequest $request, string $id)` actions; enforce `compliance.config`
- [X] T083 [P] [US5] Create `app/Modules/Audit/Infrastructure/Http/Requests/RetentionPolicyRequest.php` — validates: `data_category` (required, string, max:64), `minimum_retention_days` (required, integer, min:1), `post_retention_action` (required, in:archive,purge)
- [X] T084 [P] [US5] Create `app/Modules/Audit/Infrastructure/Http/Resources/RetentionPolicyResource.php` — fields per openapi.yaml `RetentionPolicyResponse`
- [X] T085 [US5] Register retention policy routes in `routes/audit.php`:
  - `GET /v1/compliance/retention-policies` → `RetentionPolicyController@index`
  - `POST /v1/compliance/retention-policies` → `RetentionPolicyController@store`
  - `PUT /v1/compliance/retention-policies/{id}` → `RetentionPolicyController@update`
  - All wrapped in `middleware(['permission:compliance.config'])`
- [X] T086 [US5] Unit test `tests/Unit/Modules/Audit/RetentionEnforcementTest.php` — retention command does NOT purge records within `minimum_retention_days`; command DOES archive/purge records beyond window; `enforcement_hold_until` grace period prevents processing
- [X] T087 [US5] Feature test `tests/Feature/Modules/Audit/RetentionPolicyTest.php` — create policy records a config audit event; update shortening the period sets `enforcement_hold_until`; 403 for missing `compliance.config`

**Checkpoint**: Retention rules are configurable and automatically enforced. US5 independently testable.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: OpenAPI finalization, static analysis, metrics, admin UI, and quickstart validation.

- [X] T088 Write final `openapi/v1/audit.yaml` — merge and finalize from `specs/023-audit-compliance/contracts/openapi.yaml`; all 12 endpoints documented with request/response schemas, auth requirements, and error envelopes
- [X] T089 [P] Add `audit_events_recorded_total` Prometheus counter in `AuditService::record()` — label `event_category`; register in `AppServiceProvider` or `AuditServiceProvider`
- [X] T090 [P] Add `compliance_reports_generated_total` and `sar_generated_total` Prometheus counters in respective services
- [X] T091 [P] Add structured logging (`Log::info` with correlation ID) in `AuditService::record()`, `ComplianceStatusService` state-change methods, `ComplianceReportService::requestReport()`, `SuspiciousActivityReportService::generateSAR()`; `Log::warning` on `verifyIntegrity()=false`
- [X] T092 Run PHPStan level max: `./vendor/bin/phpstan analyse app/Modules/Audit/ --level=max` — resolve all type errors
- [X] T093 Run `php artisan test --filter=Audit` — all unit and feature tests passing
- [X] T094 Admin UI (Next.js): create `apps/admin/app/(dashboard)/audit/page.tsx` — audit event list with category/date filters and detail drawer; uses shared design tokens
- [X] T095 Admin UI (Next.js): create `apps/admin/app/(dashboard)/compliance/page.tsx` — compliance status search by user ID; status card and history timeline; SAR list with create-SAR action
- [X] T096 Admin UI (Next.js): create `apps/admin/app/(dashboard)/compliance/retention/page.tsx` — retention policy list/create/edit; minimum retention days and action selectors
- [X] T097 Docker quickstart: run validation scenarios SC-1 through SC-9 from `specs/023-audit-compliance/quickstart.md` in the running Docker environment — confirm all pass

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 — **BLOCKS all user stories**
- **US1 (Phase 3)**: Depends on Phase 2 — `AuditModuleContract` must exist
- **US2 (Phase 4)**: Depends on Phase 2 — `AuditEventModel` must exist; can run in parallel with US1
- **US3 (Phase 5)**: Depends on Phase 2 — compliance status and history models needed; can run parallel with US1/US2
- **US4 (Phase 6)**: Depends on Phase 2 + benefits from US3 (compliance data) — can start after Phase 2
- **US5 (Phase 7)**: Depends on Phase 2 (schema, AuditModuleContract for self-auditing) — independent of US1–US4
- **Polish (Phase 8)**: Depends on all previous phases complete

### User Story Dependencies

- **US1 (P1)**: Only needs Phase 2 foundation
- **US2 (P2)**: Only needs Phase 2 foundation; `AuditEventModel` already has basic guard from T014
- **US3 (P3)**: Only needs Phase 2; reads from E05/E18 events (no US1/US2 dependency)
- **US4 (P4)**: Needs Phase 2; reads from `audit_events` (written by US1's foundation) and `compliance_status` (written by US3)
- **US5 (P5)**: Needs Phase 2 for `AuditModuleContract` self-audit; independent of US1–US4

### Within Each User Story

- Migrations → Models → Services → Controllers/Resources → Routes → Tests
- [P]-marked tasks within a phase can run in parallel
- All tasks write to different files unless dependency is noted

### Parallel Opportunities

- T008, T009, T010, T011, T012 (enums + exceptions in Phase 2) — fully parallel
- T020, T021, T022, T026 in Phase 3 (Request, Resources, Event) — fully parallel
- T038, T039, T040 in Phase 5 (Models + Exception) — fully parallel
- T058, T059, T060, T061 in Phase 6 (Models + Exceptions) — fully parallel
- T065, T066 (domain events in Phase 6) — fully parallel
- T079, T083, T084 in Phase 7 — fully parallel
- T089, T090, T091 in Phase 8 (metrics + logging) — fully parallel
- T094, T095, T096 in Phase 8 (admin UI pages) — fully parallel

---

## Parallel Execution Examples

```bash
# Phase 2 — Foundational: launch enum + exception tasks together
Task T008: AuditEventCategory enum
Task T009: AuditActorType enum
Task T010: AuditOutcome enum
Task T011: AuditImmutabilityViolationException
Task T012: AuditWriteException

# Phase 3 — US1: launch Request + Resources together after T014
Task T020: ListAuditEventsRequest
Task T021: AuditEventSummaryResource
Task T022: AuditEventDetailResource
Task T026: AuditEventRecordedEvent

# Phase 5 — US3: launch models together after migrations
Task T038: ComplianceStatusModel
Task T039: ComplianceHistoryModel
Task T040: ComplianceStatusNotFoundException
Task T042: KycStatusChangedListener
Task T043: AmlRiskLevelChangedListener
Task T044: AmlRestrictionChangedListener
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (AuditModuleContract + AuditService + AuditEventModel)
3. Complete Phase 3: US1 (audit event list/detail read API)
4. **STOP and VALIDATE**: Other modules can now call `AuditModuleContract::record()` and events appear in `GET /v1/audit/events`
5. Quickstart SC-1 and SC-2 pass

### Incremental Delivery

1. Setup + Foundational → `AuditModuleContract` available platform-wide
2. US1 → Compliance officers can query the audit trail
3. US2 → Immutability is verifiable and tamper attempts are detected
4. US3 → Per-user compliance status and history accessible
5. US4 → Compliance reports and SARs generatable
6. US5 → Data lifecycle managed via configurable retention policies

### Parallel Team Strategy

With multiple developers after Phase 2 completes:
- Developer A: US1 (audit event read API)
- Developer B: US2 (immutability enforcement testing) + US3 (compliance status)
- Developer C: US5 (retention policies — most independent)
- Developer A (after US1): US4 (reports/SARs — consumes US3 data)

---

## Phase 9: Convergence

**Source**: `/speckit-converge` run 2026-09-29 — 4 gaps found after Phase 8 implementation.

- [X] T098 Create `tests/Feature/Modules/Audit/ComplianceReportJobTest.php` [Docker-gated]: seed 30 days of `AuditEventModel` records, dispatch `GenerateComplianceReportJob` synchronously via `Bus::dispatchSync()`, assert `status=completed` and `report_data` non-null, assert wall time <60s using `microtime(true)` — covers SC-005 async SLA per T063 (missing)
- [X] T099 [P] Add `AUDIT_HMAC_KEY=` to `.env.example` with comment `# Required: HMAC-SHA256 secret for audit event integrity hashes — generate with: openssl rand -hex 32` per FR-011 (missing)
- [X] T100 [P] Add startup validation in `AuditServiceProvider::boot()`: `throw new \RuntimeException('AUDIT_HMAC_KEY must be set and non-empty')` when `config('audit.hmac_key')` is an empty string — prevents silent integrity-hash bypass per FR-011 (missing)
- [X] T101 [P] Add test case to `tests/Feature/Modules/Audit/SARTest.php` covering the `status=incomplete` + `subject_identity_snapshot.status=snapshot_unavailable` path introduced by the U1 remediation: mock `IdentityModuleContract::getIdentitySnapshot()` to throw `BadMethodCallException`, POST to `/v1/compliance/sars`, assert response has `status=incomplete` and `subject_identity_snapshot.status=snapshot_unavailable` per US4/AC3 (partial)

---

## Notes

- [P] tasks write to different files with no same-file conflicts
- `AuditModuleContract::record()` is the cross-cutting contract — all E01–E22 modules must be updated to inject and call it for their respective event categories (tracked per-epic, not in this tasks list)
- `audit_events` table has no soft-delete column — archiving copies to `audit_events_archive` before removing from primary; the archive table is also append-only
- PHPStan max is required before marking phase 8 complete
- Docker quickstart SC-1 through SC-9 are the acceptance gate for T097
- T098 is Docker-gated; T099/T100/T101 can run locally
