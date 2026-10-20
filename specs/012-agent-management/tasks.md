# Tasks: E12 Agent Management

**Input**: `specs/012-agent-management/` — spec.md, plan.md, research.md, data-model.md, contracts/api.yaml, quickstart.md

**Module**: `app/Modules/Agent/` | **DB**: `platform` connection, `agent` schema | **Routes**: `routes/modules/agent.php`

**Migration prefix**: `2026_09_23_001xxx` | **Tests**: `tests/{Unit,Feature,Integration,Security,Concurrency}/Modules/Agent/`

---

## Phase 1: Setup — Domain Types & Contracts

**Purpose**: Create all enums, exceptions, and domain contracts before any migrations or services. These are pure PHP files with no dependencies.

- [X] T001 Expand `app/Modules/Agent/Providers/AgentServiceProvider.php` — remove "deferred to E16" stub comment, add `register()` skeleton with placeholder bindings and `boot()` loading migrations + routes
- [X] T002 [P] Create `app/Modules/Agent/Domain/Enums/ApplicationStatus.php` — PHP enum with cases: `Draft, InProgress, Submitted, KycInProgress, KycPassed, KycFailed, UnderReview, Approved, Rejected`; add `isEditable(): bool` (returns true only for Draft/InProgress) and `isTerminal(): bool`; also create `app/Modules/Agent/Domain/Enums/AgentType.php` — PHP enum with cases: `Individual, Business`
- [X] T003 [P] Create `app/Modules/Agent/Domain/Enums/AgentStatus.php` — PHP enum with cases: `Active, Suspended`; add `isOperational(): bool`
- [X] T004 [P] Create `app/Modules/Agent/Domain/Enums/DocumentType.php` — PHP enum: `NationalId, ProofOfAddress, BusinessRegistration`
- [X] T005 [P] Create `app/Modules/Agent/Domain/Enums/DocumentStatus.php` — PHP enum: `PendingReview, Accepted, Rejected, Quarantined`; add `isPersisted(): bool` (false for Quarantined)
- [X] T006 [P] Create `app/Modules/Agent/Domain/Exceptions/ApplicationNotEditableException.php`, `ApplicationNotApprovableException.php`, `ApplicationInTerminalStatusException.php`, `ApplicationIncompleteException.php`, `MakerCheckerViolationException.php`, `AgentNotActiveException.php`, `AgentNotSuspendedException.php`, `UnauthorizedCustomerAccessException.php`, `DuplicatePhoneException.php`, `DocumentVirusDetectedException.php`, `DocumentTooLargeException.php`, `DocumentInvalidFormatException.php`, `AgentStatusHistoryImmutableException.php` — all extend `\RuntimeException`
- [X] T007 [P] Create `app/Modules/Agent/Domain/Contracts/AgentKycProviderContract.php` (interface with `verify(AgentKycPayload): AgentKycResult`), `app/Modules/Agent/Domain/ValueObjects/AgentKycPayload.php`, `app/Modules/Agent/Domain/ValueObjects/AgentKycResult.php` (with `passed: bool`, `reference: string`, `failureReason: ?string`)
- [X] T008 [P] Create `app/Modules/Agent/Domain/Contracts/AgentDocumentStorageContract.php` (interface: `store(UploadedFile): string` returns opaque reference) and `app/Modules/Agent/Domain/Contracts/VirusScannerContract.php` (interface: `scan(UploadedFile): VirusScanResult` with `isClean(): bool`); also create `app/Modules/Agent/Domain/ValueObjects/VirusScanResult.php` with `isClean(): bool` and `threatName: ?string`

---

## Phase 2: Foundational — Migrations, Models, Stubs & Service Provider

**Purpose**: All DB tables, seeds, Eloquent models, infrastructure stubs, and service bindings. MUST be complete before any controller/service work.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T009 Create `app/Modules/Agent/Infrastructure/Migrations/2026_09_23_001000_create_agent_applications_table.php` — connection `platform`; table `agent.agent_applications` with all columns from data-model.md; CHECK constraints for `agent_type IN ('individual','business')` and `status IN ('draft','in_progress','submitted','kyc_in_progress','kyc_passed','kyc_failed','under_review','approved','rejected')`; partial UNIQUE index `UNIQUE (user_id) WHERE status != 'rejected'`; indexes on `(status)`, `(user_id, status)`
- [X] T010 [P] Create `app/Modules/Agent/Infrastructure/Migrations/2026_09_23_001100_create_agent_documents_table.php` — connection `platform`; table `agent.agent_documents`; CHECK `document_type IN ('national_id','proof_of_address','business_registration')`; CHECK `status IN ('pending_review','accepted','rejected','quarantined')`; CHECK `size_bytes > 0`; index `(application_id, document_type)`, `(application_id, status)`; note: `storage_reference` is nullable (null when status=quarantined)
- [X] T011 [P] Create `app/Modules/Agent/Infrastructure/Migrations/2026_09_23_001200_create_agents_table.php` — connection `platform`; table `agent.agents`; `UNIQUE (application_id)` (CC-E12-001 guard); `UNIQUE (user_id)`; CHECK `status IN ('active','suspended')`; index `(status)`
- [X] T012 Create `app/Modules/Agent/Infrastructure/Migrations/2026_09_23_001300_create_agent_customer_relationships_table.php` — connection `platform`; table `agent.agent_customer_relationships`; `UNIQUE (agent_id, customer_id)`; FK `agent_id → agent.agents.id`; FK `customer_id → identity.users.id` DEFERRABLE; index `(customer_id)`, `(agent_id)`
- [X] T013 [P] Create `app/Modules/Agent/Infrastructure/Migrations/2026_09_23_001400_create_agent_status_history_table.php` — connection `platform`; table `agent.agent_status_history`; CHECK `actor_type IN ('admin','system')`; index `(agent_id, occurred_at DESC)`; no UPDATE/DELETE permitted (enforced at model layer)
- [X] T014 Create `app/Modules/Agent/Infrastructure/Migrations/2026_09_23_001500_seed_agent_configuration_defaults.php` — connection `platform`; insert into `platform.configuration_values` using `insertOrIgnore` for all 7 keys from data-model.md (`agent.approval.require_maker_checker=true`, `agent.approval.auto_approve=false`, `agent.kyc.provider=stub`, `agent.document.max_size_bytes=10485760`, `agent.document.allowed_formats=application/pdf,image/jpeg,image/png`, `agent.document.virus_scan.provider=null`, `agent.customer.search.min_length=3`)
- [X] T015 Create `app/Modules/Agent/Infrastructure/Migrations/2026_09_23_001600_seed_agent_permissions.php` — connection `identity`; insert 12 permission slugs into `identity.permissions` using `insertOrIgnore` with UUID v7 IDs: `agent.application.create`, `agent.application.read`, `agent.application.read.all`, `agent.approve`, `agent.reject`, `agent.suspend`, `agent.reactivate`, `agent.customer.search`, `agent.customer.register`, `agent.customer.kyc.initiate`, `agent.customer.wallet.read`, `agent.customer.transactions.read`
- [X] T016 Create `app/Modules/Agent/Infrastructure/Migrations/2026_09_23_001700_seed_agent_role.php` — connection `identity`; `insertOrIgnore` into `identity.roles` with slug=`agent`, display_name=`Agent`; then insert role_permissions linking agent role to all `agent.customer.*` + `agent.application.create` + `agent.application.read` permissions
- [X] T017 [P] Create `app/Modules/Agent/Infrastructure/Models/AgentApplicationModel.php` — `$connection='platform'`, `$table='agent.agent_applications'`; cast `status` to `ApplicationStatus` enum; cast `personal_info`, `business_info` as `array`; add `isEditable(): bool` delegating to `ApplicationStatus::isEditable()`
- [X] T018 [P] Create `app/Modules/Agent/Infrastructure/Models/AgentDocumentModel.php` — `$connection='platform'`, `$table='agent.agent_documents'`; cast `status` to `DocumentStatus` enum; cast `document_type` to `DocumentType` enum
- [X] T019 [P] Create `app/Modules/Agent/Infrastructure/Models/AgentModel.php` — `$connection='platform'`, `$table='agent.agents'`; cast `status` to `AgentStatus` enum; relationship `application()` → `AgentApplicationModel`; relationship `statusHistory()` → `AgentStatusHistoryModel`
- [X] T020 [P] Create `app/Modules/Agent/Infrastructure/Models/AgentCustomerRelationshipModel.php` — `$connection='platform'`, `$table='agent.agent_customer_relationships'`
- [X] T021 Create `app/Modules/Agent/Infrastructure/Models/AgentStatusHistoryModel.php` — `$connection='platform'`, `$table='agent.agent_status_history'`; override `performUpdate(Builder $query): bool` to throw `AgentStatusHistoryImmutableException`; override `performDeleteOnModel(): void` to throw `AgentStatusHistoryImmutableException`
- [X] T022 [P] Create `app/Modules/Agent/Infrastructure/Stubs/StubAgentKycProvider.php` implementing `AgentKycProviderContract` — reads `agent.kyc.stub_result` config (default `passed`); returns `AgentKycResult` with reference=`stub-{uuid}` and `passed=true/false` based on config
- [X] T023 [P] Create `app/Modules/Agent/Infrastructure/Stubs/NullVirusScanner.php` implementing `VirusScannerContract` — reads `agent.document.virus_scan.stub_result` config (default `clean`); in test environment supports `AGENT_VIRUS_SCAN_STUB_RESULT=infected` env override
- [X] T024 [P] Create `app/Modules/Agent/Infrastructure/Stubs/LaravelDiskAgentDocumentStorage.php` implementing `AgentDocumentStorageContract` — uses `Storage::disk(config('agent.document.storage_disk', 'agent-documents'))->putFile()`, returns path as opaque storage reference
- [X] T025 Complete `app/Modules/Agent/Providers/AgentServiceProvider.php` — bind `AgentKycProviderContract → StubAgentKycProvider`, `AgentDocumentStorageContract → LaravelDiskAgentDocumentStorage`, `VirusScannerContract → NullVirusScanner`; register all service singletons; `boot()`: `loadMigrationsFrom`, `loadRoutesFrom(base_path('routes/modules/agent.php'))`

**Checkpoint**: Run `php artisan migrate --path=app/Modules/Agent/Infrastructure/Migrations` — all 8 migrations complete without error.

---

## Phase 3: US-AGENT-001 — Create Agent Application (P0)

**Goal**: Authenticated user creates a new AgentApplication in `draft` status.

**Independent Test**: `POST /api/v1/agent-applications` with valid agent_type → 201; AgentApplication row in DB with `status=draft`; `agent.application.created` event in `platform.outbox_events`.

- [X] T026 [US1] Create `app/Modules/Agent/Application/Services/AgentApplicationService.php` with `create(string $userId, AgentType $agentType, string $idempotencyKey, string $correlationId): AgentApplicationModel` — check idempotency via `IdempotencyServiceInterface`; create `AgentApplicationModel` with `status=draft`; publish `agent.application.created` event via `EventPublisherInterface`; return model
- [X] T027 [P] [US1] Create `app/Modules/Agent/Infrastructure/Http/Requests/CreateApplicationRequest.php` — validate `agent_type` required, in `[individual, business]`
- [X] T028 [US1] Create `app/Modules/Agent/Infrastructure/Http/Controllers/AgentApplicationController.php` with `store(CreateApplicationRequest): JsonResponse` — inject `AgentApplicationService`; delegate to `create()`; return `AgentApplicationResource` with `201`
- [X] T029 [US1] Add route to `routes/modules/agent.php`: `Route::post('agent-applications', [AgentApplicationController::class, 'store'])->middleware(['identity.auth', 'identity.require-permission:agent.application.create'])`
- [X] T030 [P] [US1] Create `app/Modules/Agent/Infrastructure/Http/Resources/AgentApplicationResource.php` — expose: `id`, `agent_type`, `status`, `kyc_result`, `rejection_reason`, `submitted_at`, `approved_at`, `correlation_id`, `created_at`, `updated_at`
- [X] T031 [US1] Create `tests/Feature/Modules/Agent/CreateApplicationTest.php` — test all 4 ACs: (1) valid request → 201 + draft status, (2) unauthenticated → 401, (3) duplicate Idempotency-Key → 200 replay, (4) missing agent_type → 422 VALIDATION_ERROR
- [X] T032 [P] [US1] Create `tests/Unit/Modules/Agent/ApplicationStatusEnumTest.php` — verify: all 9 statuses exist; `isEditable()` true only for `Draft/InProgress`; `isTerminal()` true for `Approved/Rejected`; `isEditable()` false for all non-editable states including `UnderReview`

**Checkpoint**: `CreateApplicationTest` passes (4/4); `ApplicationStatusEnumTest` passes.

---

## Phase 4: US-AGENT-002 — Personal Information (P0)

**Goal**: Applicant updates personal details on a draft or in_progress application.

**Independent Test**: PATCH personal-information on a `draft` application → 200; `personal_info` JSONB populated in DB; audit event emitted.

- [X] T033 [US2] Add `updatePersonalInfo(string $applicationId, string $actorId, array $data): AgentApplicationModel` to `AgentApplicationService` — load application, verify `$actorId == application.user_id` (403 if not), assert `isEditable()` (422 APPLICATION_NOT_EDITABLE if not), merge personal_info JSONB, update `last_updated_by`, publish `agent.application.personal_info_updated` event
- [X] T034 [P] [US2] Create `app/Modules/Agent/Infrastructure/Http/Requests/UpdatePersonalInfoRequest.php` — validate: `first_name`, `last_name`, `national_id_number`, `national_id_type` (enum), `date_of_birth` (date), `phone` (E.164 regex), `address.street`, `address.city`, `address.country` (ISO 2-char) — all required
- [X] T035 [US2] Add `updatePersonalInfo(UpdatePersonalInfoRequest $request, string $id): JsonResponse` to `AgentApplicationController`
- [X] T036 [US2] Add route: `Route::patch('agent-applications/{id}/personal-information', [AgentApplicationController::class, 'updatePersonalInfo'])->middleware(['identity.auth', 'identity.require-permission:agent.application.read'])`
- [X] T037 [US2] Create `tests/Feature/Modules/Agent/PersonalInfoTest.php` — test all 5 ACs: (1) valid update → 200 + persisted, (2) different owner → 403, (3) submitted/terminal status → 422 APPLICATION_NOT_EDITABLE, (4) invalid national ID format → 422 VALIDATION_ERROR, (5) audit event emitted
- [X] T038 [P] [US2] Expand `tests/Unit/Modules/Agent/AgentApplicationServiceTest.php` — test edit-lock: assert all non-editable states throw `ApplicationNotEditableException`; assert draft + in_progress do not throw

**Checkpoint**: `PersonalInfoTest` passes (5/5).

---

## Phase 5: US-AGENT-003 — Business Information (P0)

**Goal**: Business-type applicants capture trading name and registration details.

**Independent Test**: PATCH business-information on a `business` type application → 200; `business_info` JSONB populated; `422 DUPLICATE_BUSINESS_NAME` when trading name already used by active agent.

- [X] T039 [US3] Add `updateBusinessInfo(string $applicationId, string $actorId, array $data): AgentApplicationModel` to `AgentApplicationService` — ownership check; edit-lock check; if `trading_name` provided, check no other `approved` application has same trading_name in `business_info->>'trading_name'` (422 DUPLICATE_BUSINESS_NAME); merge `business_info` JSONB; publish `agent.application.business_info_updated` event
- [X] T040 [P] [US3] Create `app/Modules/Agent/Infrastructure/Http/Requests/UpdateBusinessInfoRequest.php` — all fields optional (individual agent may submit empty); validate `registration_number` max 50, `trading_name` max 200, `tax_number` max 50 if present
- [X] T041 [US3] Add `updateBusinessInfo(UpdateBusinessInfoRequest $request, string $id): JsonResponse` to `AgentApplicationController`
- [X] T042 [US3] Add route: `Route::patch('agent-applications/{id}/business-information', [AgentApplicationController::class, 'updateBusinessInfo'])->middleware(['identity.auth', 'identity.require-permission:agent.application.read'])`
- [X] T043 [US3] Create `tests/Feature/Modules/Agent/BusinessInfoTest.php` — test all 3 ACs: (1) business type with valid data → 200 + persisted, (2) individual type with empty body → 200, (3) duplicate trading_name → 422 DUPLICATE_BUSINESS_NAME

**Checkpoint**: `BusinessInfoTest` passes (3/3).

---

## Phase 6: US-AGENT-004 — Document Upload & Virus Scan (P0)

**Goal**: Applicant uploads KYC documents. Virus scan runs before storage. Clean files are stored; infected files are quarantined with audit event.

**Independent Test**: Upload valid PDF → 201 + `AgentDocument.status=pending_review`; upload with stub set to infected → 422 DOCUMENT_VIRUS_DETECTED + `AgentDocument.status=quarantined` + `storage_reference=null` + audit event in outbox.

- [X] T044 [US4] Create `app/Modules/Agent/Application/Services/AgentDocumentService.php` with `upload(string $applicationId, string $actorId, DocumentType $type, UploadedFile $file, string $idempotencyKey): AgentDocumentModel` — (1) verify application owned by actor and is editable; (2) validate file size against `agent.document.max_size_bytes` config → throw `DocumentTooLargeException`; (3) validate MIME type against `agent.document.allowed_formats` config → throw `DocumentInvalidFormatException`; (4) call `VirusScannerContract::scan($file)` — if infected: create `AgentDocument` with `status=quarantined`, `storage_reference=null`, `rejection_reason=VIRUS_SCAN_FAILED`, publish `agent.application.document_rejected` event with `actor_type=system`, throw `DocumentVirusDetectedException`; (5) if clean: supersede previous document of same type (set prior document `status=rejected`, `rejection_reason=SUPERSEDED`); (6) call `AgentDocumentStorageContract::store($file)` → get reference; (7) create `AgentDocument` with `status=pending_review`, `storage_reference=reference`; (8) publish `agent.application.document_uploaded` event
- [X] T045 [P] [US4] Create `app/Modules/Agent/Infrastructure/Http/Requests/UploadDocumentRequest.php` — validate `document_type` required (enum), `file` required (file type); add custom size/format validation messages mapping to `DOCUMENT_TOO_LARGE` and `INVALID_DOCUMENT_FORMAT` codes
- [X] T046 [US4] Add `uploadDocument(UploadDocumentRequest $request, string $id): JsonResponse` to `AgentApplicationController` — catch `DocumentVirusDetectedException` → 422 DOCUMENT_VIRUS_DETECTED; catch `DocumentTooLargeException` → 422 DOCUMENT_TOO_LARGE; catch `DocumentInvalidFormatException` → 422 INVALID_DOCUMENT_FORMAT
- [X] T047 [US4] Add route: `Route::post('agent-applications/{id}/documents', [AgentApplicationController::class, 'uploadDocument'])->middleware(['identity.auth', 'identity.require-permission:agent.application.read'])`
- [X] T048 [P] [US4] Create `app/Modules/Agent/Infrastructure/Http/Resources/AgentDocumentResource.php` — expose: `id`, `application_id`, `document_type`, `status`, `mime_type`, `size_bytes`, `rejection_reason`, `uploaded_at`
- [X] T049 [US4] Create `tests/Feature/Modules/Agent/DocumentUploadTest.php` — test all 6 ACs: (1) valid PDF → 201 + pending_review, (2) unsupported format → 422 INVALID_DOCUMENT_FORMAT, (3) over size limit → 422 DOCUMENT_TOO_LARGE, (4) same type uploaded twice → first superseded, new active, (5) valid upload → audit event in outbox, (6) virus scan stub infected → 422 DOCUMENT_VIRUS_DETECTED + quarantined record + null storage_reference + document_rejected event
- [X] T050 [P] [US4] Create `tests/Unit/Modules/Agent/AgentDocumentServiceTest.php` — unit test quarantine path (no store call when scan fails), supersede path (prior document status set to rejected+SUPERSEDED), format validation logic, size limit enforcement

**Checkpoint**: `DocumentUploadTest` passes (6/6); virus scan quarantine path verified in DB.

---

## Phase 7: US-AGENT-005 — Agent KYC Trigger (P0)

**Goal**: Submit complete application to KYC provider. Status → `kyc_in_progress`. Stub returns configurable pass/fail via callback.

**Independent Test**: POST /kyc on complete application → 202 + `status=kyc_in_progress`; stub auto-transitions to `kyc_passed`; `422 APPLICATION_INCOMPLETE` if personal_info or documents missing.

- [X] T051 [US5] Add `triggerKyc(string $applicationId, string $actorId, string $idempotencyKey): AgentApplicationModel` to `AgentApplicationService` — assert application owned by actor; assert `status IN (submitted, in_progress, draft)` → 422 if wrong state; check completeness: `personal_info` not null AND at least one `AgentDocument` with `status=pending_review` or `accepted` AND `document_type=national_id` present → 422 APPLICATION_INCOMPLETE with missing sections; transition `status → kyc_in_progress`; call `AgentKycProviderContract::verify(AgentKycPayload)`; publish `agent.application.kyc_initiated` event; if stub: immediately transition to `kyc_passed` or `kyc_failed` based on stub config and update `kyc_result` + `kyc_provider_reference`
- [X] T052 [US5] Add `triggerKyc(Request $request, string $id): JsonResponse` to `AgentApplicationController`
- [X] T053 [US5] Add route: `Route::post('agent-applications/{id}/kyc', [AgentApplicationController::class, 'triggerKyc'])->middleware(['identity.auth', 'identity.require-permission:agent.application.read'])`
- [X] T054 [US5] Create `tests/Feature/Modules/Agent/KycTriggerTest.php` — test all 7 ACs: (1) complete application → 202 + kyc_in_progress, (2) incomplete (missing personal info or docs) → 422 APPLICATION_INCOMPLETE with details, (3) stub kyc_passed callback → status=kyc_passed, (4) stub kyc_failed → status=kyc_failed + reason, (5) KYC provider unavailable (stub throws) → status remains kyc_in_progress retry recorded, (6) admin KYC bypass flag enabled (`agent.kyc.admin_bypass=true`) → status transitions to kyc_passed + ADMIN_KYC_BYPASS audit event (FR-E12-008), (7) duplicate Idempotency-Key → 202 with current status, no duplicate `agent.application.kyc_initiated` event in outbox

**Checkpoint**: `KycTriggerTest` passes (5/5).

---

## Phase 8: US-AGENT-006 — Approve Agent Application (P0)

**Goal**: Admin approves a `kyc_passed` or `under_review` application. Creates Agent record, grants role, emits event. Maker-checker enforced.

**Independent Test**: Approve `kyc_passed` application → 200 + Agent.status=active; `UNIQUE (application_id)` on agents table prevents duplicate; `agent.approved` in outbox; agent role in `identity.user_roles`.

- [X] T055 [US6] Create `app/Modules/Agent/Application/Services/AgentRoleAssignmentService.php` — `assignAgentRole(string $userId, string $actorId): void` — query `identity.roles WHERE slug = 'agent'`; `insertOrIgnore` into `identity.user_roles (user_id, role_id, assigned_by, assigned_at)` via `identity` connection
- [X] T056 [US6] Create `app/Modules/Agent/Application/Services/AgentLifecycleService.php` with `approve(string $applicationId, string $adminActorId, ?string $notes, string $idempotencyKey): AgentModel` — (1) check idempotency; (2) `DB::transaction()`: `SELECT FOR UPDATE` on `agent_applications WHERE id=? AND status IN ('kyc_passed','kyc_failed','under_review')`; (3) assert application found → 422 APPLICATION_NOT_APPROVABLE; (4) if `require_maker_checker=true` and `application.last_updated_by === $adminActorId` → throw MakerCheckerViolationException; (5) transition application `status → under_review` if currently `kyc_passed/kyc_failed` and `auto_approve=false`; (6) transition application `status → approved`; (7) create `AgentModel` with `status=active`; (8) create `AgentStatusHistory` row; (9) call `AgentRoleAssignmentService::assignAgentRole()`; (10) publish `agent.approved` event via outbox; (11) return `AgentModel`
- [X] T057 [P] [US6] Create `app/Modules/Agent/Infrastructure/Http/Requests/ApproveApplicationRequest.php` — optional `notes` string max 500
- [X] T058 [US6] Create `app/Modules/Agent/Infrastructure/Http/Controllers/AgentLifecycleController.php` with `approve(ApproveApplicationRequest $request, string $id): JsonResponse` — catch `MakerCheckerViolationException` → 422; catch `ApplicationNotApprovableException` → 422
- [X] T059 [US6] Add route: `Route::post('agent-applications/{id}/approve', [AgentLifecycleController::class, 'approve'])->middleware(['identity.auth', 'identity.require-permission:agent.approve'])`
- [X] T060 [P] [US6] Create `app/Modules/Agent/Infrastructure/Http/Resources/AgentResource.php` — expose: `id`, `application_id`, `user_id`, `agent_type`, `status`, `approved_at`, `suspended_at`, `created_at`, `updated_at`
- [X] T061 [US6] Create `tests/Feature/Modules/Agent/ApproveApplicationTest.php` — test all 5 ACs + maker-checker + idempotency: (1) kyc_passed → 200 + Agent.status=active, (2) maker-checker violation → 422 MAKER_CHECKER_VIOLATION, (3) wrong status → 422 APPLICATION_NOT_APPROVABLE, (4) duplicate Idempotency-Key → 200 existing Agent, (5) audit event `agent.approved` in outbox
- [X] T062 [US6] Create `tests/Concurrency/Modules/Agent/ConcurrentApprovalTest.php` — CC-E12-001: simulate two concurrent approve calls on same application_id; assert exactly one 200 and one 422; assert `SELECT COUNT(*) FROM agent.agents WHERE application_id=? = 1` (UNIQUE constraint guard)
- [X] T063 [P] [US6] Create `tests/Unit/Modules/Agent/AgentStatusHistoryImmutableTest.php` — verify `AgentStatusHistoryModel::performUpdate()` throws `AgentStatusHistoryImmutableException` when `$this->exists=true`; verify `performDeleteOnModel()` throws too

**Checkpoint**: `ApproveApplicationTest` passes (7/7); `ConcurrentApprovalTest` demonstrates exactly 1 Agent row created.

---

## Phase 9: US-AGENT-007 — Reject Agent Application (P0)

**Goal**: Admin rejects a `kyc_passed`, `kyc_failed`, or `under_review` application with a mandatory reason.

**Independent Test**: Reject with reason → 200 + `application.status=rejected` + `rejection_reason` populated; `422 APPLICATION_IN_TERMINAL_STATUS` when already approved/rejected.

- [X] T064 [US7] Add `reject(string $applicationId, string $adminActorId, string $reason, string $idempotencyKey): AgentApplicationModel` to `AgentLifecycleService` — check idempotency; `DB::transaction()`: `SELECT FOR UPDATE` on application; assert `status IN ('kyc_passed','kyc_failed','under_review')` → 422 APPLICATION_IN_TERMINAL_STATUS if already terminal; transition `status → under_review` if `kyc_passed/kyc_failed` and `auto_approve=false` (implicit); transition `status → rejected`; set `rejection_reason`, `rejected_at`, `last_updated_by`; publish `agent.rejected` event
- [X] T065 [P] [US7] Create `app/Modules/Agent/Infrastructure/Http/Requests/RejectApplicationRequest.php` — `reason` required, `minLength:10`, `maxLength:1000`
- [X] T066 [US7] Add `reject(RejectApplicationRequest $request, string $id): JsonResponse` to `AgentLifecycleController`
- [X] T067 [US7] Add route: `Route::post('agent-applications/{id}/reject', [AgentLifecycleController::class, 'reject'])->middleware(['identity.auth', 'identity.require-permission:agent.reject'])`
- [X] T068 [US7] Create `tests/Feature/Modules/Agent/RejectApplicationTest.php` — test all 5 ACs: (1) kyc_passed + reason → 200 + rejected, (2) missing reason → 422 VALIDATION_ERROR, (3) already approved/rejected → 422 APPLICATION_IN_TERMINAL_STATUS, (4) `agent.rejected` event in outbox, (5) duplicate Idempotency-Key → 200 with already-rejected application, no duplicate `agent.rejected` event in outbox

**Checkpoint**: `RejectApplicationTest` passes (4/4).

---

## Phase 10: US-AGENT-008 — Suspend Active Agent (P0)

**Goal**: Admin suspends an active agent with mandatory reason. Emits `agent.suspended` to outbox (E03 consumes to revoke sessions).

**Independent Test**: Suspend `active` agent → 200 + `Agent.status=suspended`; `agent.suspended` in outbox with `user_id`; `AgentStatusHistory` row created; suspended agent search attempt → 403 AGENT_SUSPENDED.

- [X] T069 [US8] Add `suspend(string $agentId, string $adminActorId, string $reason, string $idempotencyKey): AgentModel` to `AgentLifecycleService` — check idempotency; `DB::transaction()`: `SELECT FOR UPDATE` on `agent.agents WHERE id=?`; assert `status=active` → throw `AgentNotActiveException`; if `require_maker_checker=true` and maker-checker violation → throw `MakerCheckerViolationException`; transition `status → suspended`; set `suspended_by`, `suspension_reason`, `suspended_at`; create `AgentStatusHistory` row; publish `agent.suspended` event (with `user_id` payload for E03)
- [X] T070 [P] [US8] Create `app/Modules/Agent/Infrastructure/Http/Requests/SuspendAgentRequest.php` — `reason` required, `minLength:10`, `maxLength:1000`
- [X] T071 [US8] Add `suspend(SuspendAgentRequest $request, string $id): JsonResponse` to `AgentLifecycleController` — catch `AgentNotActiveException` → 422 AGENT_NOT_ACTIVE; catch `MakerCheckerViolationException` → 422
- [X] T072 [US8] Add route: `Route::post('agents/{id}/suspend', [AgentLifecycleController::class, 'suspend'])->middleware(['identity.auth', 'identity.require-permission:agent.suspend'])`
- [X] T073 [US8] Create `tests/Feature/Modules/Agent/SuspendAgentTest.php` — test all 5 ACs: (1) active agent + reason → 200 + suspended, (2) already suspended → 422 AGENT_NOT_ACTIVE, (3) maker-checker violation → 422 MAKER_CHECKER_VIOLATION, (4) `agent.suspended` event in outbox with `user_id`, (5) duplicate Idempotency-Key → 200 with suspended agent, no duplicate `agent.suspended` event in outbox
- [X] T074 [US8] Create `tests/Concurrency/Modules/Agent/ConcurrentSuspensionTest.php` — CC-E12-002: two concurrent suspend calls; assert one 200 and one 422; agent ends in `suspended` exactly once; one `agent.suspended` outbox event
- [X] T075 [US8] Create `tests/Integration/Modules/Agent/AgentSuspensionEventTest.php` — SC-E12-005: measure time from suspend call to `agent.suspended` outbox event created; assert < 1000ms (E12 scope only — session revocation latency is E03's responsibility per FR-E12-021)

**Checkpoint**: `SuspendAgentTest` passes (4/4); `agent.suspended` event has `user_id` field for E03 consumption.

---

## Phase 11: US-AGENT-010 — Search Agent Customers (P0)

**Goal**: Active agent searches existing customers by phone, name, or masked ID. Returns limited PII.

**Independent Test**: GET /agents/{id}/customers?search=John → 200 + `CustomerSummary[]`; national_id masked to last 4 digits; suspended agent → 403 AGENT_SUSPENDED; search < 3 chars → 422 SEARCH_TOO_SHORT.

- [X] T076 [US10] Create `app/Modules/Agent/Application/Services/AgentCustomerService.php` with `assertLinked(string $agentId, string $customerId): void` — query `agent.agent_customer_relationships WHERE agent_id=? AND customer_id=?`; if not found: publish `security.unauthorized_access_attempt` event; throw `UnauthorizedCustomerAccessException` (→ 403, never 404)
- [X] T077 [US10] Add `assertAgentActive(string $agentId): AgentModel` to `AgentCustomerService` — load agent; if `status=suspended` throw `AgentNotActiveException` (→ 403 AGENT_SUSPENDED)
- [X] T078 [US10] Add `searchCustomers(string $agentId, string $query, int $page, int $perPage): LengthAwarePaginator` to `AgentCustomerService` — call `assertAgentActive()`; validate query length against `agent.customer.search.min_length` config; query `identity.users` by phone LIKE, first_name LIKE, or masked ID; return paginated results
- [X] T079 [P] [US10] Create `app/Modules/Agent/Infrastructure/Http/Requests/SearchCustomersRequest.php` — `search` required string; `page` optional int default 1; `per_page` optional int default 20 max 100
- [X] T080 [US10] Create `app/Modules/Agent/Infrastructure/Http/Controllers/AgentCustomerController.php` with `search(SearchCustomersRequest $request, string $id): JsonResponse`
- [X] T081 [US10] Add route: `Route::get('agents/{id}/customers', [AgentCustomerController::class, 'search'])->middleware(['identity.auth', 'identity.require-permission:agent.customer.search'])`
- [X] T082 [P] [US10] Create `app/Modules/Agent/Infrastructure/Http/Resources/CustomerSummaryResource.php` — mask `national_id`: `'****'.substr($nationalId, -4)`; expose only: `id`, `first_name`, `last_name`, `masked_national_id`, `phone`, `status` — NO full national_id, no PIN, no password hash
- [X] T083 [US10] Create `tests/Feature/Modules/Agent/SearchCustomersTest.php` — test all 5 ACs: (1) active agent search → 200 + CustomerSummary[], (2) suspended agent → 403 AGENT_SUSPENDED, (3) < 3 char query → 422 SEARCH_TOO_SHORT, (4) no matches → 200 + empty data[], (5) `agent.customer.searched` event in outbox

**Checkpoint**: `SearchCustomersTest` passes (5/5); national_id masked in all responses.

---

## Phase 12: US-AGENT-011 — Register New Customer (P0)

**Goal**: Active agent registers a new customer, creating a user account and AgentCustomerRelationship.

**Independent Test**: POST /agents/{id}/customers → 201 + CustomerSummary; `agent_customer_relationships` row exists; `agent.customer.registered` in outbox; duplicate phone → 422 PHONE_ALREADY_REGISTERED.

- [X] T084 [US11] Add `registerCustomer(string $agentId, array $data, string $idempotencyKey): array` to `AgentCustomerService` — check idempotency; call `assertAgentActive()`; check `identity.users WHERE phone=?` → throw `DuplicatePhoneException` if exists; `DB::transaction()`: create minimal identity user record (`identity.users` via identity connection); create `AgentCustomerRelationship` with `agent_id`, `customer_id`, `registered_by=agentId`; publish `agent.customer.registered` event (E06 consumes to open wallet); return customer summary
- [X] T085 [P] [US11] Create `app/Modules/Agent/Infrastructure/Http/Requests/RegisterCustomerRequest.php` — `phone` required (E.164 regex), `first_name` required max 100, `last_name` required max 100, `id_type` required enum `[national_id, passport]`, `id_number` required max 50
- [X] T086 [US11] Add `register(RegisterCustomerRequest $request, string $id): JsonResponse` to `AgentCustomerController` — catch `DuplicatePhoneException` → 422 PHONE_ALREADY_REGISTERED; catch `AgentNotActiveException` → 403 AGENT_SUSPENDED
- [X] T087 [US11] Add route: `Route::post('agents/{id}/customers', [AgentCustomerController::class, 'register'])->middleware(['identity.auth', 'identity.require-permission:agent.customer.register'])`
- [X] T088 [US11] Create `tests/Feature/Modules/Agent/RegisterCustomerTest.php` — test all 5 ACs: (1) valid data → 201 + relationship created, (2) duplicate phone → 422 PHONE_ALREADY_REGISTERED, (3) suspended agent → 403 AGENT_SUSPENDED, (4) `agent.customer.registered` in outbox, (5) same Idempotency-Key → 200 replay no duplicate
- [X] T089 [P] [US11] Create `tests/Integration/Modules/Agent/AgentFullOnboardingTest.php` — SC-E12-001: full 5-step application (create→personal info→business info→document→KYC) + approve + register customer; measure wall-clock time; assert complete flow < 10 minutes (timing validation); assert all audit events present in outbox

**Checkpoint**: `RegisterCustomerTest` passes (5/5); relationship row exists in DB.

---

## Phase 13: US-AGENT-012 — Initiate Customer KYC (P0)

**Goal**: Active agent initiates KYC for a linked customer in `pending_kyc` status.

**Independent Test**: POST .../kyc → 202; `agent.customer.kyc_initiated` event in outbox; 403 for unlinked customer (IDOR check).

- [X] T090 [US12] Add `initiateCustomerKyc(string $agentId, string $customerId, string $actorId, string $idempotencyKey): void` to `AgentCustomerService` — check idempotency; call `assertAgentActive()`; call `assertLinked($agentId, $customerId)`; check customer status is `pending_kyc` → 422 KYC_ALREADY_COMPLETED if already `kyc_verified`; publish `agent.customer.kyc_initiated` event (E05 consumes to open KYC case for customer)
- [X] T091 [US12] Add `initiateCustomerKyc(Request $request, string $id, string $cid): JsonResponse` to `AgentCustomerController` — catch `UnauthorizedCustomerAccessException` → 403 UNAUTHORIZED_CUSTOMER_ACCESS (never 404)
- [X] T092 [US12] Add route: `Route::post('agents/{id}/customers/{cid}/kyc', [AgentCustomerController::class, 'initiateCustomerKyc'])->middleware(['identity.auth', 'identity.require-permission:agent.customer.kyc.initiate'])`
- [X] T093 [US12] Create `tests/Feature/Modules/Agent/CustomerKycTest.php` — test all 4 ACs: (1) linked pending_kyc customer → 202 + event, (2) unlinked customer → 403 UNAUTHORIZED_CUSTOMER_ACCESS (not 404), (3) already kyc_verified → 422 KYC_ALREADY_COMPLETED, (4) `agent.customer.kyc_initiated` event in outbox

**Checkpoint**: `CustomerKycTest` passes (4/4); IDOR always returns 403 not 404.

---

## Phase 14: US-AGENT-013 — View Customer Wallet Status (P0)

**Goal**: Active agent views wallet status (status, tier) for a linked customer. Balance is null per TBD-E12-001.

**Independent Test**: GET .../wallet for linked customer → 200 + `{wallet_id, status, tier, is_frozen, balance_disclosure: null}`; unlinked → 403; `agent.customer.wallet_viewed` in outbox.

- [X] T094 [US13] Add `viewCustomerWallet(string $agentId, string $customerId): array` to `AgentCustomerService` — call `assertAgentActive()`; call `assertLinked()`; query `wallet.wallets WHERE user_id=?` on `platform` connection (cross-schema read); publish `agent.customer.wallet_viewed` event; return `{wallet_id, status, tier, is_frozen, balance_disclosure: null}` (TBD-E12-001: exact balance not disclosed)
- [X] T095 [US13] Add `viewWallet(Request $request, string $id, string $cid): JsonResponse` to `AgentCustomerController` — catch `UnauthorizedCustomerAccessException` → 403
- [X] T096 [US13] Add route: `Route::get('agents/{id}/customers/{cid}/wallet', [AgentCustomerController::class, 'viewWallet'])->middleware(['identity.auth', 'identity.require-permission:agent.customer.wallet.read'])`
- [X] T097 [P] [US13] Create `app/Modules/Agent/Infrastructure/Http/Resources/CustomerWalletViewResource.php` — expose: `wallet_id`, `status`, `tier`, `is_frozen`, `balance_disclosure` (always null until TBD-E12-001 resolved) — MUST NOT expose raw balance amount
- [X] T098 [US13] Create `tests/Feature/Modules/Agent/CustomerWalletViewTest.php` — test all 5 ACs: (1) linked customer → 200 + wallet fields, (2) unlinked → 403 UNAUTHORIZED_CUSTOMER_ACCESS, (3) suspended agent → 403 AGENT_SUSPENDED, (4) frozen wallet → `is_frozen:true` in response, (5) `agent.customer.wallet_viewed` event in outbox

**Checkpoint**: `CustomerWalletViewTest` passes (5/5); `balance_disclosure` is always `null`.

---

## Phase 15: US-AGENT-014 — View Customer Transactions (P0)

**Goal**: Active agent views agent-channel transactions for a linked customer. Only transactions sourced through this agent are returned.

**Independent Test**: GET .../transactions → 200 + paginated `AgentTransactionSummary[]`; non-agent-channel transactions excluded; unlinked → 403.

- [X] T099 [US14] Add `viewCustomerTransactions(string $agentId, string $customerId, int $page, int $perPage): LengthAwarePaginator` to `AgentCustomerService` — call `assertAgentActive()`; call `assertLinked()`; query `transaction.transaction_projections` on `platform` connection WHERE `customer_id=? AND metadata->>'source_agent_id'=?` (agent-channel filter per FR-E12-019); paginate; publish `agent.customer.transactions_viewed` event
- [X] T100 [US14] Add `viewTransactions(Request $request, string $id, string $cid): JsonResponse` to `AgentCustomerController`
- [X] T101 [US14] Add route: `Route::get('agents/{id}/customers/{cid}/transactions', [AgentCustomerController::class, 'viewTransactions'])->middleware(['identity.auth', 'identity.require-permission:agent.customer.transactions.read'])`
- [X] T102 [P] [US14] Create `app/Modules/Agent/Infrastructure/Http/Resources/AgentTransactionResource.php` — expose: `id`, `type`, `status`, `amount`, `currency_code`, `direction`, `reference`, `created_at` — no sensitive metadata
- [X] T103 [US14] Create `tests/Feature/Modules/Agent/CustomerTransactionViewTest.php` — test all 3 ACs: (1) linked customer → 200 + paginated agent-channel transactions only, (2) unlinked → 403 UNAUTHORIZED_CUSTOMER_ACCESS, (3) `agent.customer.transactions_viewed` event in outbox

**Checkpoint**: `CustomerTransactionViewTest` passes (3/3); non-agent transactions excluded from results.

---

## Phase 16: US-AGENT-009 — Reactivate Suspended Agent (P1)

**Goal**: Admin reactivates a `suspended` agent, restoring operational access.

**Independent Test**: Reactivate `suspended` agent → 200 + `Agent.status=active`; `agent.reactivated` in outbox; already-active agent → 422 AGENT_NOT_SUSPENDED.

- [X] T104 [US9] Add `reactivate(string $agentId, string $adminActorId, ?string $notes, string $idempotencyKey): AgentModel` to `AgentLifecycleService` — check idempotency; `DB::transaction()`: `SELECT FOR UPDATE` on agent; assert `status=suspended` → throw `AgentNotSuspendedException`; transition `status → active`; set `reactivated_by`, `reactivated_at`; create `AgentStatusHistory` row; publish `agent.reactivated` event
- [X] T105 [P] [US9] Create `app/Modules/Agent/Infrastructure/Http/Requests/ReactivateAgentRequest.php` — optional `notes` string max 500
- [X] T106 [US9] Add `reactivate(ReactivateAgentRequest $request, string $id): JsonResponse` to `AgentLifecycleController` — catch `AgentNotSuspendedException` → 422 AGENT_NOT_SUSPENDED
- [X] T107 [US9] Add route: `Route::post('agents/{id}/reactivate', [AgentLifecycleController::class, 'reactivate'])->middleware(['identity.auth', 'identity.require-permission:agent.reactivate'])`
- [X] T108 [US9] Create `tests/Feature/Modules/Agent/ReactivateAgentTest.php` — test all 4 ACs (including M3 idempotency fix): (1) suspended → 200 + active, (2) already active → 422 AGENT_NOT_SUSPENDED, (3) `agent.reactivated` in outbox, (4) duplicate Idempotency-Key → 200 current agent no duplicate transition

**Checkpoint**: `ReactivateAgentTest` passes (4/4).

---

## Phase 17: Polish & Cross-Cutting Concerns

**Purpose**: Security enforcement, concurrency validation, OpenAPI verification, observability checks, and final PHPStan gate.

- [X] T109 Create `tests/Security/Modules/Agent/AgentCustomerInjectionTest.php` — SC-E12-004: test all 4 customer data endpoints (search, wallet, transactions, kyc-initiate) with a customer_id belonging to a different agent; assert ALL return 403 UNAUTHORIZED_CUSTOMER_ACCESS; assert NONE return 404; assert `security.unauthorized_access_attempt` event emitted for each attempt (100% enforcement)
- [X] T110 [P] Create `tests/Security/Modules/Agent/AgentPermissionEnforcementTest.php` — assert each of the 12 route/permission pairs: unauthenticated → 401, missing permission → 403; verify no endpoint is accessible without the correct permission slug
- [X] T111 [P] Create `tests/Security/Modules/Agent/AgentPiiStorageTest.php` — FR-E12-022: verify no endpoint response includes full national_id number, PIN, or password hash for customer data; verify `CustomerSummaryResource` always masks to last-4 digits; verify `CustomerWalletViewResource` never exposes raw balance
- [X] T112 [P] Create `tests/Unit/Modules/Agent/AgentStatusEnumTest.php` — verify all 3 status enums: `ApplicationStatus` (9 cases, isEditable, isTerminal), `AgentStatus` (2 cases, isOperational), `DocumentStatus` (4 cases, isPersisted); verify state transitions match FR-E12-013
- [X] T113 Verify all 13 domain events have correct payload structure and SC-E12-007 timing — write `tests/Unit/Modules/Agent/DomainEventPayloadTest.php` asserting required fields: `correlation_id`, `actor_id`, `occurred_at` on each event class; add integration timing assertions for approve + suspend operations measuring elapsed time from service entry to `outbox_events` INSERT; assert < 100ms per event (SC-E12-007)
- [X] T114 [P] Run quickstart.md validation scenarios 1–10 manually (or as Integration test suite) — confirm all pass; document results in `checklists/requirements.md` notes section
- [X] T115 Run `php artisan route:list | grep agent` — verify all 14 API routes registered at correct paths with correct middleware
- [X] T116 [P] Run PHPStan level 8: `./vendor/bin/phpstan analyse app/Modules/Agent/ --level=8` — resolve all errors; add `ignoreErrors` for pre-approved Larastan generic type patterns only
- [X] T119 Create `tests/Performance/Modules/Agent/CustomerSearchPerformanceTest.php` — SC-E12-003: seed 10 000 customer records linked to test agent; assert `GET /api/v1/agents/{id}/customers?search=Jo` responds within 2 000ms (p95, in-process PHPUnit timing); verify index `(agent_id)` on `agent_customer_relationships` is used (EXPLAIN check)
- [X] T120 Create `tests/Integration/Modules/Agent/AdminReviewLatencyTest.php` — SC-E12-002: time POST `/api/v1/agent-applications/{id}/approve` end-to-end round-trip from HTTP dispatch to 200 response; assert < 120 000ms; this guards against latency regressions, not a strict SLO gate
- [X] T117 Run full test suite: `php artisan test --filter=Agent` — assert 0 failures; record final count
- [X] T118 Update `specs/012-agent-management/checklists/requirements.md` notes — record final test count, PHPStan result, quickstart validation status

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (Setup)**: No dependencies — start immediately; T002-T008 all parallel
- **Phase 2 (Foundational)**: Depends on Phase 1; T009-T016 are DB migrations (sequential by timestamp); T017-T024 are model/stub files (parallel); T025 depends on T017-T024
- **Phase 3–15 (User Stories)**: All depend on Phase 2 completion; stories can proceed in the listed order (P0 stories first)
- **Phase 16 (US-AGENT-009)**: Depends on Phase 10 (AgentLifecycleService exists)
- **Phase 17 (Polish)**: Depends on all user story phases complete

### User Story Dependencies

| Story | Depends on |
|-------|-----------|
| US-AGENT-001 (Create App) | Phase 2 only |
| US-AGENT-002 (Personal Info) | US-AGENT-001 (AgentApplicationService exists) |
| US-AGENT-003 (Business Info) | US-AGENT-001 |
| US-AGENT-004 (Documents) | US-AGENT-001 |
| US-AGENT-005 (KYC) | US-AGENT-004 (documents uploaded before KYC) |
| US-AGENT-006 (Approve) | US-AGENT-005 (application must reach kyc_passed) |
| US-AGENT-007 (Reject) | US-AGENT-005 |
| US-AGENT-008 (Suspend) | US-AGENT-006 (Agent record must exist) |
| US-AGENT-009 (Reactivate) | US-AGENT-008 (Agent must be suspended) |
| US-AGENT-010 (Search) | Phase 2 (AgentCustomerService) |
| US-AGENT-011 (Register) | US-AGENT-010 (AgentCustomerService + assertAgentActive) |
| US-AGENT-012 (Customer KYC) | US-AGENT-011 (relationship must exist) |
| US-AGENT-013 (Wallet View) | US-AGENT-011 |
| US-AGENT-014 (Transactions) | US-AGENT-011 |
| US-AGENT-015 (Auth enforcement) | US-AGENT-010–014 (cross-cutting, in Phase 17) |

### Parallel Opportunities

```bash
# Phase 1 — run T002-T008 in parallel (all independent files):
T002 ApplicationStatus enum
T003 AgentStatus enum
T004 DocumentType enum
T005 DocumentStatus enum
T006 11 exception classes
T007 KYC provider contract + value objects
T008 Storage + virus scanner contracts

# Phase 2 — migrations sequential; models/stubs parallel:
T009-T016 (sequential by timestamp order)
T017, T018, T019, T020 (parallel — different model files)
T022, T023, T024 (parallel — different stub files)

# Phase 3 — T027, T030 parallel; T028 after T026; T031, T032 parallel:
T026 (service) → T027 [P], T028 → T029 → T031, T032 [P]

# Security phase — T109, T110, T111, T112, T113 parallel (different test files)
```

---

## Implementation Strategy

### MVP (Phase 1 + 2 + Phase 3 only — US-AGENT-001)

1. Complete Phase 1: Domain types
2. Complete Phase 2: Migrations + models + stubs + service provider
3. Complete Phase 3: Create application endpoint
4. **Validate**: `POST /api/v1/agent-applications` returns 201 with draft status
5. Proceed to Phase 4+

### Incremental Delivery

- **Sprint 1 (P0 core)**: Phases 1–7 (onboarding flow complete)
- **Sprint 2 (P0 admin)**: Phases 8–10 (approve/reject/suspend)
- **Sprint 3 (P0 operations)**: Phases 11–15 (agent-customer operations)
- **Sprint 4 (P1 + polish)**: Phase 16 (reactivate) + Phase 17 (security/polish)

---

## Notes

- `[P]` = can run in parallel with other `[P]` tasks in the same phase (different files, no dependencies)
- `[USx]` = maps to User Story x from spec.md for traceability
- All service methods are on **application services** — controllers stay thin (request parse → service call → resource response)
- Every state transition: (1) assert preconditions, (2) DB transaction with SELECT FOR UPDATE, (3) mutate, (4) publish outbox event — in that order
- `assertLinked()` is called on EVERY agent-customer data request — no exceptions; always 403 not 404
- TBD-E12-001 (`balance_disclosure=null`) must remain null until formally resolved — do not invent a value
