# Tasks: E24 — Reporting & Analytics

**Input**: Design documents from `specs/024-reporting-analytics/`

**Prerequisites**: plan.md ✓, spec.md ✓, research.md ✓, data-model.md ✓, contracts/reporting.yaml ✓, quickstart.md ✓

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks in this phase)
- **[Story]**: Which user story this task belongs to (US1–US6)
- All paths relative to `wallet-ecosystem/services/api/`

---

## Phase 1: Setup

**Purpose**: Module scaffold, schema registration, service provider wiring — required before any story begins.

- [X] T001 Create `app/Modules/Reporting/ReportingServiceProvider.php` — `register()`: binds `ReportingModuleContract` (populated after T015); `boot()`: `loadMigrationsFrom(__DIR__.'/Infrastructure/Migrations')`, `loadRoutesFrom(base_path('routes/modules/reporting.php'))`, registers scheduler commands (populated after T047 and T059)
- [X] T001b Add `reports` storage disk to `config/filesystems.php` — new entry in the `disks` array: `'reports' => ['driver' => env('REPORTS_STORAGE_DRIVER', 'local'), 'root' => storage_path('app/reports'), 'url' => env('APP_URL').'/storage/reports', 'visibility' => 'private']`; add S3 env vars to `.env.example`: `REPORTS_STORAGE_DRIVER`, `REPORTS_S3_BUCKET`, `REPORTS_S3_REGION`; add `if (config('filesystems.disks.reports.driver') === 'local') { Storage::disk('reports')->makeDirectory(''); }` in `ReportingServiceProvider::boot()` to ensure local directory exists on app boot (H1 fix — resolves `Storage::disk('reports')` runtime exception)
- [X] T001c [P] Create `config/reporting.php` — returns array bridging E28 config to `config('reports.*')` keys; each value attempts `app(\App\Modules\Configuration\Domain\Contracts\ConfigurationServiceInterface::class)->get($e28Key, $default)` inside a `try/catch`, falling back to env/hardcoded default if E28 not ready at boot; keys and defaults exactly matching `data-model.md` E28 key names: `execution_timeout_seconds` (300), `concurrent_jobs_per_user` (3), `max_rows_default` (100000), `export_link_ttl_seconds` (3600), `export_file_retention_days` (7), `alert_failure_threshold` (5), `alert_window_minutes` (5); all job handler `config('reports.*')` references in T018–T040 must use these exact key names (H2 fix — ensures E28 seeded values are read at runtime instead of PHP defaults being hardcoded forever)
- [X] T002 [P] Create `routes/modules/reporting.php` — empty file with `Route::prefix('v1')->middleware(['identity.auth'])->group(static function(): void {})` shell; individual routes are added in later tasks
- [X] T003 Create `app/Modules/Reporting/Infrastructure/Migrations/2026_10_01_000001_create_reporting_schema.php` — `DB::statement('CREATE SCHEMA IF NOT EXISTS reporting')` using `platform` connection
- [X] T004 Register `App\Modules\Reporting\ReportingServiceProvider::class` in `bootstrap/providers.php`

**Checkpoint**: Module registered, `reporting` schema created, route file in place.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: `report_definitions` catalogue, `report_jobs` table, domain enums/exceptions, application service for job submission (with concurrent limit), permission seeding, and config seeding — MUST be complete before any user story begins.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T005 Create `app/Modules/Reporting/Infrastructure/Migrations/2026_10_01_000002_create_report_definitions_table.php` — `reporting.report_definitions` per data-model.md; `id` UUID v7 PK; unique index on `type`; uses `platform` connection
- [X] T006 Create `app/Modules/Reporting/Infrastructure/Migrations/2026_10_01_000003_create_report_jobs_table.php` — `reporting.report_jobs` per data-model.md; `id` UUID v7 PK; `result_data jsonb NULLABLE`; `filters jsonb NOT NULL`; `status varchar(32) NOT NULL DEFAULT 'requested'`; indexes: `(requested_by_user_id, status)`, `(report_type, status, created_at)`, `(status, created_at)`; uses `platform` connection
- [X] T007 [P] Create `app/Modules/Reporting/Domain/Enums/ReportJobStatus.php` — backed string enum: `Requested='requested'`, `Processing='processing'`, `Complete='complete'`, `Failed='failed'`; `isFinal(): bool` (true for Complete and Failed); `canTransitionTo(self $next): bool` allows only `Requested→Processing`, `Processing→Complete`, `Processing→Failed`
- [X] T008 [P] Create `app/Modules/Reporting/Domain/Enums/ReportType.php` — backed string enum with all 10 slugs: `UserReport='user-report'`, `WalletReport='wallet-report'`, `TransactionReport='transaction-report'`, `AgentReport='agent-report'`, `CommissionReport='commission-report'`, `KycReport='kyc-report'`, `ReconciliationReport='reconciliation-report'`, `SettlementReport='settlement-report'`, `RiskReport='risk-report'`, `FinancialSummary='financial-summary'`
- [X] T009 [P] Create domain exceptions in `app/Modules/Reporting/Domain/Exceptions/`: `ReportJobNotFoundException.php` (HTTP 404, code `REPORT_JOB_NOT_FOUND`); `ConcurrentJobLimitExceededException.php` (HTTP 429, code `CONCURRENT_JOB_LIMIT_EXCEEDED`, carries `int $activeCount` and `int $limit`); `RowLimitExceededException.php` (HTTP 422, code `ROW_LIMIT_EXCEEDED`); `ReportJobNotCompleteException.php` (HTTP 409, code `REPORT_JOB_NOT_COMPLETE`); `ReportJobCancelException.php` (HTTP 409, code `REPORT_JOB_CANNOT_CANCEL`)
- [X] T010 Create `app/Modules/Reporting/Infrastructure/Models/ReportDefinitionModel.php` — `$connection='platform'`, `$table='reporting.report_definitions'`; cast `filter_schema` as `array`; scope `active()` filters `is_active=true`
- [X] T011 Create `app/Modules/Reporting/Infrastructure/Models/ReportJobModel.php` — `$connection='platform'`, `$table='reporting.report_jobs'`; cast `filters` as `array`, `result_data` as `array`; cast `status` as `ReportJobStatus` enum; `boot()` registers `creating` hook generating UUID v7 via `UuidV7::generate()` (grep `app/Modules/Audit/Infrastructure/Models/AuditEventModel.php` or `app/Modules/Wallet/Infrastructure/Models` for exact import path — same E07/E23 pattern; do NOT use `Str::orderedUuid()` which produces UUID v4 not v7); `transitionTo(ReportJobStatus $next): void` validates via `canTransitionTo()`, throws `\LogicException` on invalid transition, saves model
- [X] T012 Create `database/seeders/ReportDefinitionSeeder.php` — upserts all 10 `ReportDefinition` records using `updateOrCreate(['type' => ...])` with: `name`, `description`, `required_permission` per data-model.md permissions table (`admin.read` for user/wallet/transaction/agent; `finance.reports` for reconciliation/settlement/financial-summary; `compliance.reports` for kyc/risk; `commission.reports` for commission — use `admin.read` if commission module already uses that permission), `pii_sensitive` (true only for `user-report` and `kyc-report`), `filter_schema` (minimal JSON Schema: `{type:object, properties:{date_range_start:{type:string,format:date},date_range_end:{type:string,format:date}},required:[date_range_start,date_range_end]}`), `is_active=true`; call from `DatabaseSeeder::run()`
- [X] T013 [P] Create `database/seeders/ReportPermissionSeeder.php` — upserts permissions using E04's `Permission::updateOrCreate(['slug'=>...])` pattern: `admin.read`, `finance.reports`, `compliance.reports`, `reports.schedule`, `compliance.pii.read` per data-model.md; skip if permission already exists; call from `DatabaseSeeder::run()`
- [X] T014 [P] Create `database/seeders/ReportConfigSeeder.php` — upserts E28 config keys using `ConfigurationService::set()` or direct `ConfigurationValueModel::updateOrCreate()`: `reports.max_rows_default=100000`, `reports.execution_timeout_seconds=300`, `reports.concurrent_jobs_per_user=3`, `reports.export_link_ttl_seconds=3600`, `reports.export_file_retention_days=7`, `reports.alert_failure_threshold=5`, `reports.alert_window_minutes=5`; call from `DatabaseSeeder::run()`
- [X] T015 Create `app/Modules/Reporting/Domain/Contracts/ReportingModuleContract.php` — interface: `getJobStatus(string $jobId): ?ReportJobStatus`; `listJobsForUser(string $userId, array $filters): LengthAwarePaginator`
- [X] T016 Create `app/Modules/Reporting/Application/Services/ReportJobApplicationService.php` with methods: `submit(string $userId, ReportType $type, array $filters, bool $piiAccess, string $correlationId): ReportJobModel` — resolves definition, validates filters against `filter_schema` (throw `ValidationException`), **(2b — M2 fix, enforces FR-006)** checks estimated row count via a private `estimateRowCount(ReportType $type, array $filters): int` method that runs `SELECT COUNT(*) FROM {source_table} WHERE date_range_filters` for the report type (each ReportType maps to its primary source table); if count > `config('reports.max_rows_default', 100000)` throw `RowLimitExceededException($count, $limit)` — this check runs BEFORE acquiring the concurrent-limit lock to avoid holding a transaction during a slow COUNT query, counts active jobs inside `DB::connection('platform')->transaction()` with `lockForUpdate()`, throws `ConcurrentJobLimitExceededException` if count >= config limit, creates `ReportJobModel` with `status=Requested`, records audit event via injected `AuditServiceInterface` with action `REPORT_JOB_REQUESTED`, returns created model; **NOTE (H3 fix)**: job status transitions (`processing`, `complete`, `failed`) MUST also be audited inside each job handler — see T018 for the required `handle(AuditServiceInterface $audit)` injection and three-call audit pattern; apply the same pattern to all 10 job handler classes; `getJob(string $jobId, string $userId): ReportJobModel` — find scoped to `requested_by_user_id`, throw `ReportJobNotFoundException`; `cancel(string $jobId, string $userId): void` — find job, throw `ReportJobCancelException` if status ≠ `Requested`, transition to `Failed` with `failure_reason='cancelled_by_user'`, record audit event; `listJobs(string $userId, array $filters, int $perPage): LengthAwarePaginator`; bind via `ReportingModuleContract` in `ReportingServiceProvider::register()`
- [X] T017 Unit test `tests/Unit/Modules/Reporting/ReportJobApplicationServiceTest.php` — mock `AuditServiceInterface`, `ReportDefinitionModel`, `ReportJobModel`; assert: (a) `submit()` creates job with `Requested` status; (b) `submit()` with 3 active jobs throws `ConcurrentJobLimitExceededException` with `activeCount=3, limit=3`; (c) `cancel()` on `Requested` job transitions to `Failed`; (d) `cancel()` on `Processing` job throws `ReportJobCancelException`; (e) `submit()` with missing `date_range_start` throws `ValidationException`; (f) mock `estimateRowCount()` returning 100001 with `config('reports.max_rows_default')=100000`; assert `submit()` throws `RowLimitExceededException` with `rowCount=100001, limit=100000` (N2 fix — covers FR-006 row limit enforcement step 2b)

**Checkpoint**: `ReportDefinitionSeeder`, concurrent limit enforcement, and `ReportJobApplicationService::submit()` are functional. Job handlers can now be added per story.

---

## Phase 3: User Story 1 — Transaction & Wallet Reports (Priority: P1) 🎯 MVP

**Goal**: Authorized users can submit wallet and transaction report jobs, poll for completion, and retrieve structured results with an as-of timestamp.

**Independent Test**: Submit `wallet-report` with a date-range filter, poll until `complete`, call `GET /v1/reports/jobs/{id}` and verify `result_data` contains wallet rows with `wallet_id`, `currency`, `balance`, `status` fields and `as_of_at` is set (quickstart SC-2).

- [X] T018 Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateWalletReportJob.php` — implements `ShouldQueue`, uses `Dispatchable`, `InteractsWithQueue`, `Queueable`, `SerializesModels`; `$timeout` = `(int) config('reports.execution_timeout_seconds', 300)`; `handle(AuditServiceInterface $audit)`: (1) find `ReportJobModel`, call `transitionTo(Processing)`, set `started_at=now()`, `as_of_at=now()`, save; record audit event `$audit->record(action: 'REPORT_JOB_PROCESSING', subjectType: 'report_jobs', subjectId: $job->id, actorId: $job->requested_by_user_id)`; (2) query `DB::connection('platform')->table('wallet.wallets')` filtered by `date_range_start/end`, optional `status`, `currency`, `user_id` from job `filters`; use `LazyCollection::cursor()` for > 10k rows, else `get()`; (3) map rows to result array `{wallet_id, owner_user_id, currency, balance, status, created_at}`; (4) call `transitionTo(Complete)`, set `result_data={wallets:[...], as_of_at:...}`, `row_count`, `completed_at=now()`, save; record audit event `$audit->record(action: 'REPORT_JOB_COMPLETED', subjectType: 'report_jobs', subjectId: $job->id, actorId: $job->requested_by_user_id)`; (5) publish `ReportJobCompletedEvent` via `OutboxEventPublisher`; catch `\Throwable $e` → if `$e instanceof \Illuminate\Contracts\Queue\TimeoutExceeded` set `failure_reason='execution_timeout'`; else set `failure_reason=substr($e->getMessage(), 0, 512)`; call `transitionTo(Failed)`, set `completed_at=now()`, save; record audit event `$audit->record(action: 'REPORT_JOB_FAILED', subjectType: 'report_jobs', subjectId: $job->id, actorId: $job->requested_by_user_id)`; publish `ReportJobFailedEvent`, re-throw; **H3 fix**: apply same `handle(AuditServiceInterface $audit)` signature and three audit call pattern (`PROCESSING`, `COMPLETED`, `FAILED`) to all 10 job handler classes (T019, T031, T032, T034, T035, T036, T037, T039, T040)
- [X] T018b Unit test `tests/Unit/Modules/Reporting/GenerateWalletReportJobTest.php` — mock `ReportJobModel` (transitions callable), `AuditServiceInterface`, and the `DB::connection('platform')` query builder; assert: (a) happy path — query returns 2 rows; `transitionTo(Processing)` and `transitionTo(Complete)` both called; `result_data` and `as_of_at` set; (b) timeout path — mock query throwing `\Illuminate\Contracts\Queue\TimeoutExceeded`; assert `transitionTo(Failed)` called with `failure_reason='execution_timeout'` exactly (M3 fix — covers FR-007); (c) generic error path — mock query throwing a `\RuntimeException('db error')`; assert `failure_reason='db error'` (not 'execution_timeout'); (d) three `AuditServiceInterface::record()` calls verified: `REPORT_JOB_PROCESSING` always, `REPORT_JOB_COMPLETED` on success, `REPORT_JOB_FAILED` on failure
- [X] T019 [P] Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateTransactionReportJob.php` — same structure as T018; queries `transaction.transactions` (grep E11 source for exact table name and columns); filter by `transaction_type`, `status`, `amount_min`, `amount_max`, `currency`, date range from job `filters`; result row fields per spec US1 scenario 2: `transaction_id`, `type`, `amount`, `currency`, `status`, `sender_id`, `receiver_id`, `created_at`, `reference`
- [X] T020 [P] Create domain events in `app/Modules/Reporting/Domain/Events/`: `ReportJobRequestedEvent.php`, `ReportJobCompletedEvent.php`, `ReportJobFailedEvent.php` — all `final readonly class implements IntegrationEventInterface`; fields per data-model.md domain events table; publish `ReportJobRequestedEvent` from `ReportJobApplicationService::submit()` after job creation
- [X] T021 [P] Create `app/Modules/Reporting/Infrastructure/Http/Requests/SubmitReportJobRequest.php` — validated: `report_type` (in:user-report,wallet-report,...all 10 slugs), `filters` (array, required); `resolvedType(): ReportType` helper; `piiAccess(): bool` checks if authenticated user has `compliance.pii.read` permission
- [X] T022 [P] Create `app/Modules/Reporting/Infrastructure/Http/Requests/ListReportJobsRequest.php` — validated query params: `status` (nullable, in:requested,processing,complete,failed), `report_type` (nullable, string), `page` (integer min 1, default 1), `per_page` (integer 1–100, default 20)
- [X] T023 [P] Create `app/Modules/Reporting/Infrastructure/Http/Resources/ReportJobSummaryResource.php` — fields per `contracts/reporting.yaml` ReportJobSummary: `id`, `report_type`, `status`, `row_count`, `failure_reason`, `as_of_at`, `created_at`, `started_at`, `completed_at`
- [X] T024 [P] Create `app/Modules/Reporting/Infrastructure/Http/Resources/ReportJobDetailResource.php` — extends summary with `filters`, `result_data` (included only when `status==complete`, null otherwise)
- [X] T025 Create `app/Modules/Reporting/Infrastructure/Http/Controllers/SubmitReportJobController.php` — `__invoke(SubmitReportJobRequest $request)`: (1) look up `required_permission` from `ReportDefinitionModel`; (2) enforce via `AuthorizationGuard` or `$request->user()->cannot(...)` → 403; (3) call `ReportJobApplicationService::submit()`; (4) dispatch correct job handler via `match($type) { ReportType::WalletReport => GenerateWalletReportJob::dispatch($job->id, $correlationId), ... }`; return `ReportJobSummaryResource` HTTP 202
- [X] T026 [P] Create `app/Modules/Reporting/Infrastructure/Http/Controllers/ListReportJobsController.php` — `__invoke(ListReportJobsRequest $request)`: call `service->listJobs()`; return paginated `ReportJobSummaryResource` collection
- [X] T027 [P] Create `app/Modules/Reporting/Infrastructure/Http/Controllers/GetReportJobController.php` — `__invoke(string $id)`: call `service->getJob()`; return `ReportJobDetailResource`; catch `ReportJobNotFoundException` → 404
- [X] T028 [P] Create `app/Modules/Reporting/Infrastructure/Http/Controllers/CancelReportJobController.php` — `__invoke(string $id)`: call `service->cancel()`; return HTTP 204; catch `ReportJobCancelException` → 409
- [X] T029 Register job routes in `routes/modules/reporting.php` inside the `identity.auth` middleware group: `POST /reports/jobs` → `SubmitReportJobController`; `GET /reports/jobs` → `ListReportJobsController`; `GET /reports/jobs/{id}` → `GetReportJobController`; `DELETE /reports/jobs/{id}` → `CancelReportJobController`
- [X] T030 Feature test `tests/Feature/Modules/Reporting/ReportJobTest.php` — (a) submit `wallet-report` with `admin.read` token → 202, `status=requested`; (b) use `Bus::dispatchNow()` to run job synchronously; poll `GET /reports/jobs/{id}` → `status=complete`, `as_of_at` non-null, `result_data` non-null; (c) submit 4 jobs simultaneously → 4th returns 429 with code `CONCURRENT_JOB_LIMIT_EXCEEDED`; (d) cancel `requested` job → 204; (e) cancel `complete` job → 409; (f) submit `wallet-report` with unauthenticated request → 401; (g) submit `reconciliation-report` with `admin.read` only (no `finance.reports`) → 403; (h) `GET /reports/jobs/{id}` for another user's job → 403 or 404

**Checkpoint**: Wallet and transaction reports submit, process, and return results. US1 independently testable via quickstart SC-2, SC-4, SC-5.

---

## Phase 4: User Story 2 — Reconciliation & Settlement Reports (Priority: P1)

**Goal**: Finance admins with `finance.reports` permission can generate reconciliation and settlement reports; matched/unmatched item breakdown is present.

**Independent Test**: Submit `reconciliation-report`, dispatch synchronously, verify `result_data.items` contains entries with `reconciliation_status` field set to `matched`, `unmatched`, or `exception`.

- [X] T031 Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateReconciliationReportJob.php` — queries `reconciliation.reconciliation_items` joined with `reconciliation.reconciliation_batches` (grep E20 source for exact table names); filter by `date_range_start/end`, optional `batch_id`, optional `status`; result row fields: `transaction_id`, `reconciliation_status`, `provider_reference`, `expected_amount`, `reported_amount`, `variance_amount`, `currency`, `reconciled_at`; cursor for large datasets; same lifecycle as T018
- [X] T032 [P] Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateSettlementReportJob.php` — queries `reconciliation.settlement_batches` and `reconciliation.settlement_items` (grep E20 source); result row fields: `batch_id`, `settlement_date`, `total_amount`, `currency`, `status`, `participant_count`, `created_at`; same lifecycle as T018
- [X] T033 Feature test `tests/Feature/Modules/Reporting/ReconciliationReportTest.php` — (a) submit `reconciliation-report` with `finance.reports` token → 202; (b) submit same with `admin.read` only → 403; (c) submit `settlement-report` with `finance.reports` → 202; (d) dispatched job completes; result has `items` array and `as_of_at`; (e) each result row contains `reconciliation_status` field

**Checkpoint**: Reconciliation and settlement reports work with correct permission gating. US2 independently testable.

---

## Phase 5: User Story 3 — Agent, Commission & KYC Reports (Priority: P1)

**Goal**: Ops and compliance admins can generate user, agent, commission, and KYC reports; PII fields are masked when the requesting user lacks `compliance.pii.read`.

**Independent Test**: Submit `kyc-report` with a token that has `compliance.reports` but not `compliance.pii.read`; verify `national_id_number` absent from result rows; repeat with `compliance.pii.read` and confirm field is present.

- [X] T034 Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateUserReportJob.php` — queries `identity.users` joined with `kyc.kyc_verifications` (count per tier) and `wallet.wallets` (count); filter by `date_range_start/end`, optional `kyc_status`, optional `account_status`; result row fields: `user_id`, `registration_date`, `kyc_tier`, `wallet_count`, `account_status`; if `pii_access=false` on `ReportJobModel`, omit `email` and `phone` fields (mask as `***`); uses cursor for large datasets; same lifecycle as T018
- [X] T035 [P] Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateAgentReportJob.php` — queries `agent.agents` (grep E12 source for exact table name); aggregate transaction volume from `transaction.transactions` by agent and currency for the date range; result row fields: `agent_id`, `name`, `region`, `active_wallet_count`, `transaction_volume` (array by currency — no cross-currency total), `float_balance`, `status`; same lifecycle as T018
- [X] T036 [P] Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateCommissionReportJob.php` — queries commission records (grep E14 source for the commission ledger table in `commission` or `agent` schema); filter by `agent_id`, `commission_status`, date range; result row fields: `agent_id`, `transaction_reference`, `commission_amount`, `currency`, `status`, `settlement_date`; same lifecycle as T018
- [X] T037 Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateKycReportJob.php` — queries `kyc.kyc_verifications` joined with `identity.users`; filter by `date_range_start/end`, optional `kyc_tier`, optional `verification_result`; result row fields: `user_id`, `document_type`, `kyc_tier`, `verification_date`, `result`, `reviewer_reference`; if `pii_access=false`: exclude `national_id_number`, set `biometric_reference_id` to null; reads `pii_access` from `ReportJobModel`; same lifecycle as T018
- [X] T038 Feature test `tests/Feature/Modules/Reporting/PiiMaskingTest.php` — (a) submit `user-report` with token lacking `compliance.pii.read`: job created with `pii_access=false`, `email` in result is `***`; (b) same report with `compliance.pii.read`: job has `pii_access=true`, `email` present; (c) submit `kyc-report` without `compliance.pii.read`: `national_id_number` absent from result rows; (d) with `compliance.pii.read`: field present; (e) submit `agent-report` with `admin.read` → 202, completes without error

**Checkpoint**: All four job types generate correctly; PII masking enforced at job level via `pii_access` flag. US3 independently testable.

---

## Phase 6: User Story 4 — Risk & Financial Summary Reports (Priority: P1)

**Goal**: Compliance officers can generate risk reports; finance admins can generate financial summary reports with a per-currency balance validation assertion stored on the result.

**Independent Test**: Seed known ledger entries; submit `financial-summary`; dispatch synchronously; verify `result_data.balance_validates=true` and `result_data.currencies` is a non-empty array with `total_inflow`, `total_outflow`, `total_fees`, `net_position` per currency (quickstart SC-6).

- [X] T039 Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateRiskReportJob.php` — queries `risk.aml_flags` (grep E18 for exact table name), `risk.velocity_breaches`, and `risk.user_restrictions` (grep E18 for exact table names); result: `{flag_count_by_category: {}, velocity_breach_count: N, users_with_active_restrictions: N, top_flagged_users: [{identifier: ..., flag_count: N}]}`; if `pii_access=false`: hash user IDs in `top_flagged_users` using `substr(hash('sha256', $userId), 0, 12)` instead of real UUID; requires `compliance.reports` permission (enforced upstream in controller); same lifecycle as T018
- [X] T040 Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateFinancialSummaryReportJob.php` — queries `ledger.journal_entries` (grep E07 source for exact table/schema) grouped by `entry_type` and `currency` for the date range; aggregates: `total_inflow` (sum of credit entries for funding/transfer-in types), `total_outflow` (sum of debit entries for withdrawal/transfer-out), `total_fees` (sum of fee entries); balance assertion per currency: `$validates = abs($inflow - $outflow - $fees - $netPosition) <= 0.001`; result: `{currencies: [{currency, total_inflow, total_outflow, total_fees, net_position, balance_validates}], overall_balance_validates: bool}`; if any currency fails: set `overall_balance_validates=false`, emit structured log `WARNING` with job ID, currency, and variance — **never fail the job on assertion mismatch**; same lifecycle as T018
- [X] T041 Feature test `tests/Feature/Modules/Reporting/FinancialSummaryTest.php` — (a) submit `financial-summary` with `finance.reports` token → 202; (b) `admin.read` only on `financial-summary` → 403; (c) submit `risk-report` with `compliance.reports` → 202; (d) dispatched financial summary job completes; `result_data.currencies` is array; (e) seed 3 ledger entries (inflow 100 BWP, outflow 80 BWP, fee 2 BWP → net 18 BWP); verify `balance_validates=true`, `net_position=18.00`; (f) risk report without `compliance.pii.read`: `top_flagged_users` contains hashed identifiers (12 hex chars), not real UUIDs

**Checkpoint**: Risk and financial summary reports generate with balance validation. US4 independently testable via quickstart SC-6.

---

## Phase 7: User Story 5 — Report Export (Priority: P1)

**Goal**: Authorized users can download completed reports as CSV or Excel files via time-limited authenticated download links; expired links return 410; admins can regenerate links within the retention window.

**Independent Test**: Complete a wallet-report job; request CSV export; poll until `ready`; download the URL; confirm file starts with a CSV header row and `row_count` matches (quickstart SC-3, SC-9).

- [X] T042 Create `app/Modules/Reporting/Infrastructure/Migrations/2026_10_01_000004_create_report_exports_table.php` — `reporting.report_exports` per data-model.md; `id` UUID v7 PK; `status varchar(16) DEFAULT 'pending'`; indexes: `(job_id)`, `(file_retention_expires_at) WHERE purged_at IS NULL`, `(download_token) WHERE download_token IS NOT NULL`; uses `platform` connection
- [X] T043 Create `app/Modules/Reporting/Infrastructure/Models/ReportExportModel.php` — `$connection='platform'`, `$table='reporting.report_exports'`; `boot()` registers `creating` hook generating UUID v7 via `UuidV7::generate()` — same import as T011; `isPurged(): bool`; `isLinkExpired(): bool` (`token_expires_at < now()`); `isFileRetained(): bool` (`file_retention_expires_at > now() && !isPurged()`)
- [X] T044 Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateReportExportJob.php` — `handle()`: (1) find `ReportExportModel`, set `status='generating'`, save; (2) find parent `ReportJobModel`; (3) open temp file via `tmpfile()`; write header row via `fputcsv()`; stream `result_data` rows using `fputcsv()` (for `row_count > 10000`: re-query via cursor using same job filters, re-execute query rather than relying on cached JSONB); (4) compute storage path `"exports/{jobId}/{exportId}.csv"`; `Storage::disk('reports')->put($path, fopen($tmpFile, 'r'))`; (5) set `file_size_bytes`, `status='ready'`, generate HMAC token: `hash_hmac('sha256', "{$exportId}|{$expiry}", config('app.key'))`, set `token_expires_at`, `file_retention_expires_at = token_expires_at->addDays(config('reports.export_file_retention_days', 7))`; (6) publish `ReportExportGeneratedEvent` via `OutboxEventPublisher`; record audit event; catch `\Throwable` → set `status='failed'`
- [X] T045 [P] Create `app/Modules/Reporting/Infrastructure/Jobs/GenerateExcelExportJob.php` — same lifecycle as T044 but write `.xlsx`; confirm `phpoffice/phpspreadsheet` is in `composer.json` (`composer require phpoffice/phpspreadsheet` if absent); use `\PhpOffice\PhpSpreadsheet\Spreadsheet` with streaming writer `\PhpOffice\PhpSpreadsheet\Writer\Xlsx`; header row cells set to bold via `$sheet->getStyle('A1:Z1')->getFont()->setBold(true)`; storage path ends in `.xlsx`
- [X] T046 Create `app/Modules/Reporting/Application/Services/ReportExportApplicationService.php`: `requestExport(string $jobId, string $userId, string $format, string $correlationId): ReportExportModel` — find job scoped to user, throw `ReportJobNotCompleteException` if status ≠ `complete`, create `ReportExportModel` with `status='pending'`, dispatch `GenerateReportExportJob` or `GenerateExcelExportJob`, record audit event, return model; `getExport(string $exportId, string $userId): array` — find export, verify ownership via parent job, if `isPurged()` throw `\Symfony\Component\HttpKernel\Exception\GoneHttpException`, return model with `download_url` populated when `ready` and not expired (use `Storage::disk('reports')->temporaryUrl($path, $tokenExpiry)` for S3; for local disk return signed URL `/v1/reports/exports/{id}/download?token={token}&expires={ts}`); `regenerateLink(string $exportId, string $adminUserId, string $correlationId): ReportExportModel` — verify admin permission, find export, throw `GoneHttpException` if purged, generate new token and expiry, save, record audit event
- [X] T047 Create `app/Modules/Reporting/Infrastructure/Console/Commands/PurgeReportExportsCommand.php` — Artisan command `reports:purge-exports`; queries `report_exports` where `file_retention_expires_at <= now() AND purged_at IS NULL`; for each: `Storage::disk('reports')->delete($storage_path)`, set `purged_at=now()`, save; log count; register in `ReportingServiceProvider::boot()` via `$schedule->command('reports:purge-exports')->dailyAt('03:00')->timezone('UTC')`
- [X] T048 [P] Create `app/Modules/Reporting/Domain/Events/ReportExportGeneratedEvent.php` — `final readonly class implements IntegrationEventInterface`; fields: `exportId`, `jobId`, `format`, `userId`, `correlationId`, `occurredAt`
- [X] T049 Create `app/Modules/Reporting/Infrastructure/Http/Controllers/RequestExportController.php` — `__invoke(string $jobId, Request $request)`: validate `format` (in:csv,excel); call `ReportExportApplicationService::requestExport()`; return `ReportExportResource` HTTP 202
- [X] T050 [P] Create `app/Modules/Reporting/Infrastructure/Http/Controllers/GetExportController.php` — `__invoke(string $exportId)`: call `service->getExport()`; catch `GoneHttpException` → return HTTP 410 with code `EXPORT_PURGED`; return `ReportExportResource`
- [X] T051 [P] Create `app/Modules/Reporting/Infrastructure/Http/Controllers/RegenerateLinkController.php` — `__invoke(string $exportId)`: enforce `admin.write` permission; call `service->regenerateLink()`; return `ReportExportResource`
- [X] T052 [P] Create `app/Modules/Reporting/Infrastructure/Http/Resources/ReportExportResource.php` — fields per `contracts/reporting.yaml` ReportExport schema; `download_url` null when not `ready` or purged
- [X] T053 Add export routes in `routes/modules/reporting.php`: `POST /reports/jobs/{jobId}/exports` → `RequestExportController`; `GET /reports/exports/{exportId}` → `GetExportController`; `POST /reports/exports/{exportId}/regenerate-link` → `RegenerateLinkController` with extra `identity.require-permission:admin.write`
- [X] T054 Feature test `tests/Feature/Modules/Reporting/ReportExportTest.php` — (a) request CSV export for complete job → 202, `status=pending`; (b) request export for `processing` job → 409; (c) dispatch `GenerateReportExportJob` synchronously; `status=ready`, `download_url` non-null; (d) set `token_expires_at` to past in DB; `GET /reports/exports/{id}` returns `download_url=null`; (e) `regenerate-link` as admin: new non-null `download_url`; (f) set `purged_at` in DB; `GET /reports/exports/{id}` returns 410; `regenerate-link` also returns 410; (g) request Excel export for same job → 202, dispatches `GenerateExcelExportJob`; (h) verify audit event emitted for export generation

**Checkpoint**: CSV and Excel exports stream correctly; link expiry and regeneration work; purge command cleans up files. US5 independently testable via quickstart SC-3 and SC-9.

---

## Phase 8: User Story 6 — Scheduled Reports (Priority: P2)

**Goal**: Authorized users configure recurring schedules; the scheduler dispatches jobs automatically; recipients receive in-app and opt-in email notifications; systemic failures emit a platform ops alert.

**Independent Test**: Create daily schedule, set `next_run_at` to past in DB, run `php artisan reports:process-schedules`, verify a new `ReportJob` is created and `next_run_at` advances by 1 day; deactivate and confirm no further jobs on next run (quickstart SC-7).

- [X] T055 Create `app/Modules/Reporting/Infrastructure/Migrations/2026_10_01_000005_create_report_schedules_table.php` — `reporting.report_schedules` per data-model.md; `id` UUID v7 PK; `cadence_day_of_month` check constraint `CHECK (cadence_day_of_month BETWEEN 1 AND 28)`; index `(status, next_run_at)`; uses `platform` connection
- [X] T056 [P] Create `app/Modules/Reporting/Infrastructure/Migrations/2026_10_01_000006_create_schedule_runs_table.php` — `reporting.schedule_runs` per data-model.md; `id` UUID v7 PK; index `(schedule_id, triggered_at DESC)`; `BEFORE UPDATE/DELETE` triggers: `RAISE EXCEPTION 'schedule_run_immutable'`; uses `platform` connection
- [X] T057 Create `app/Modules/Reporting/Infrastructure/Models/ReportScheduleModel.php` — `$connection='platform'`, `$table='reporting.report_schedules'`; cast `filters` as `array`, `recipients` as `array`; `computeNextRunAt(CarbonImmutable $from): CarbonImmutable` — computes next occurrence of `cadence_time` after `$from` based on cadence (daily: next day at `cadence_time`; weekly: next `cadence_day_of_week` at `cadence_time`; monthly: next occurrence of `cadence_day_of_month` at `cadence_time`, capped at day 28)
- [X] T058 [P] Create `app/Modules/Reporting/Infrastructure/Models/ScheduleRunModel.php` — `$connection='platform'`, `$table='reporting.schedule_runs'`; `boot()` registers `updating`/`deleting` hooks throwing `\RuntimeException('schedule_run_immutable')`
- [X] T059 Create `app/Modules/Reporting/Infrastructure/Console/Commands/ProcessReportSchedulesCommand.php` — Artisan command `reports:process-schedules`; option `--dry-run`; queries `report_schedules` where `status='active' AND next_run_at <= now()`; for each schedule within `DB::connection('platform')->transaction()`: (1) create `ReportJobModel` with `requested_by_user_id=schedule->owner_user_id`, `filters=schedule->filters`, `report_type=schedule->report_type`, `correlation_id=Str::uuid()`; (2) dispatch job handler via same `match` as `SubmitReportJobController`; (3) create `ScheduleRunModel` with `outcome='dispatched'`, `job_id`; publish `OutboxEventPublisher::publish(new ReportScheduleTriggeredEvent(scheduleId: $schedule->id, jobId: $job->id, triggeredAt: now(), correlationId: $correlationId))` (L3 fix — closes dead-event gap from T061); (4) update `schedule->last_run_at=now()`, `schedule->next_run_at=computeNextRunAt(now())`; on dispatch exception: create `ScheduleRunModel` with `outcome='failed'`, `failure_reason`; if `--dry-run`: output schedule name and next_run_at without dispatching; register in `ReportingServiceProvider::boot()` via `$schedule->command('reports:process-schedules')->everyMinute()`
- [X] T060 Create `app/Modules/Reporting/Infrastructure/Jobs/Concerns/IncrementsFailureCounter.php` — trait; `protected function recordJobFailure(string $reportType, string $correlationId): void` — increments Redis key `reports:failures:{$reportType}:{minute_bucket}` (minute_bucket = `floor(time()/60)`) with TTL = `alert_window_minutes * 60`; counts all matching buckets in window; if total >= `alert_failure_threshold`: dispatch `ReportFailureThresholdExceededEvent` via `OutboxEventPublisher`, delete all matching Redis keys to reset; **apply this trait** to all 10 job handler classes (T018, T019, T031, T032, T034, T035, T036, T037, T039, T040): add `use IncrementsFailureCounter;` and call `$this->recordJobFailure($job->report_type, $this->correlationId)` in each catch block
- [X] T061 [P] Create domain events in `app/Modules/Reporting/Domain/Events/`: `ReportFailureThresholdExceededEvent.php` (fields: `reportType`, `failureCount`, `windowMinutes`, `threshold`, `correlationId`, `occurredAt`); `ReportScheduleTriggeredEvent.php` (fields: `scheduleId`, `jobId`, `triggeredAt`, `correlationId`); both `final readonly class implements IntegrationEventInterface`
- [X] T062 Create `app/Modules/Reporting/Application/Services/ReportScheduleApplicationService.php`: `create(string $userId, array $data, string $correlationId): ReportScheduleModel` — validate `report_type` (active definition must exist), `cadence`, `cadence_time`, cross-field cadence constraints; create model with `status='active'`, `next_run_at=computeNextRunAt(now())`; record audit event; `update(string $scheduleId, string $userId, array $data, string $correlationId): ReportScheduleModel` — find scoped to owner, update fields, recompute `next_run_at` if cadence changes, record audit event; `deactivate(string $scheduleId, string $userId, string $correlationId): void` — set `status='inactive'`, save, record audit event; `getSchedule`, `listSchedules`, `listRuns` — standard query methods with user-scoped access
- [X] T063 [P] Create `app/Modules/Reporting/Infrastructure/Http/Requests/CreateReportScheduleRequest.php` — validates per `contracts/reporting.yaml` CreateReportScheduleRequest; custom rule: `cadence_day_of_week` required when `cadence=weekly`; `cadence_day_of_month` required when `cadence=monthly`; `recipients` array validated: each entry has required `user_id` (uuid) and optional `email` (email format)
- [X] T064 [P] Create `app/Modules/Reporting/Infrastructure/Http/Requests/UpdateReportScheduleRequest.php` — all fields optional; same cadence cross-field validation as create; `sometimes` rules
- [X] T065 [P] Create `app/Modules/Reporting/Infrastructure/Http/Resources/ReportScheduleResource.php` — fields per `contracts/reporting.yaml` ReportSchedule schema
- [X] T066 [P] Create `app/Modules/Reporting/Infrastructure/Http/Resources/ScheduleRunResource.php` — fields per `contracts/reporting.yaml` ScheduleRun schema
- [X] T067 Create schedule controllers in `app/Modules/Reporting/Infrastructure/Http/Controllers/`: `ListSchedulesController.php`, `CreateScheduleController.php`, `GetScheduleController.php`, `UpdateScheduleController.php`, `DeactivateScheduleController.php`, `ListScheduleRunsController.php` — each delegates to `ReportScheduleApplicationService`; `DeactivateScheduleController` uses `__invoke` returning 204
- [X] T068 Add schedule routes in `routes/modules/reporting.php` within `middleware(['identity.auth', 'identity.require-permission:reports.schedule'])` group per `contracts/reporting.yaml`: `GET /reports/schedules`, `POST /reports/schedules`, `GET /reports/schedules/{id}`, `PUT /reports/schedules/{id}`, `DELETE /reports/schedules/{id}`, `GET /reports/schedules/{id}/runs`
- [X] T069 Feature test `tests/Feature/Modules/Reporting/ReportScheduleTest.php` — (a) create daily schedule with `reports.schedule` token → 201, `status=active`, `next_run_at` > now; (b) set `next_run_at` to past; run `ProcessReportSchedulesCommand`; verify new `ReportJob` created with matching `report_type` and `filters`; `next_run_at` advanced by ~1 day; (c) run command again without changing `next_run_at` → no additional job created; (d) deactivate schedule; set `next_run_at` to past; run command → no job created for inactive schedule; (e) list runs → contains one entry from (b) with `outcome='dispatched'`; (f) create schedule without `reports.schedule` permission → 403; (g) `--dry-run` flag: command outputs schedule count, creates no jobs or run records; (h) weekly schedule: `next_run_at` advances by 7 days after trigger

**Checkpoint**: Schedules create, trigger, and deactivate correctly; ops alert event published when threshold exceeded. US6 independently testable via quickstart SC-7.

---

## Phase 9: Catalogue, Notifications & Polish

**Purpose**: Report type catalogue endpoint, E17 notification integration, Admin Portal pages, PHPStan, and OpenAPI.

- [X] T070 Create `app/Modules/Reporting/Infrastructure/Http/Resources/ReportDefinitionResource.php` — fields: `id`, `type`, `name`, `description`, `required_permission`, `filter_schema`
- [X] T071 Create `app/Modules/Reporting/Infrastructure/Http/Controllers/ListReportDefinitionsController.php` — `__invoke(Request $request)`: query all active `ReportDefinitionModel` records; filter to types the authenticated user has permission to access (check `required_permission` against `$request->user()->permissions`); return `ReportDefinitionResource` collection (no pagination — max 10 items)
- [X] T072 Add catalogue route in `routes/modules/reporting.php`: `GET /reports/catalogue` → `ListReportDefinitionsController`; within `identity.auth` middleware only (no specific permission required — filtering is done inside controller)
- [X] T073 Add E17 notification integration: create `app/Modules/Reporting/Infrastructure/Listeners/ReportJobCompletedListener.php` — listens to `ReportJobCompletedEvent`; checks `reporting.schedule_runs` for a record with `job_id = $event->jobId`; if found, load parent `ReportScheduleModel`; dispatch `NotificationRequestedEvent` (grep E17 source for exact class name and fields) with `channel='in_app'` for all `recipients`; for recipients with non-null `email` when `email_notification_enabled=true`, dispatch a second `NotificationRequestedEvent` with `channel='email'`; register in `ReportingServiceProvider::boot()` via `Event::listen(ReportJobCompletedEvent::class, ReportJobCompletedListener::class)`
- [X] T074 [P] Create `app/Modules/Reporting/Infrastructure/Listeners/ReportJobFailedListener.php` — same pattern as T073 but for `ReportJobFailedEvent`; include `failure_reason` in notification payload; register in `ReportingServiceProvider::boot()`
- [X] T075 [P] Admin Portal: create `wallet-ecosystem/apps/admin/src/app/(dashboard)/reports/page.tsx` — report list page: filter bar (report type dropdown, status filter, date range pickers), paginated job table (columns: type, status, row count, created-at, actions); action buttons: "New Report" (opens report type selector), "View", "Download"; uses existing Admin Portal `DataTable`, `FilterBar`, and design system tokens
- [X] T076 [P] Admin Portal: create `wallet-ecosystem/apps/admin/src/app/(dashboard)/reports/[id]/page.tsx` — job detail page: status badge, filters summary, result row count, "Export CSV" and "Export Excel" buttons (disabled when not complete); auto-polls `GET /v1/reports/jobs/{id}` every 5s while status is `requested` or `processing` using `SWR` or `React Query` with `refreshInterval`
- [X] T077 [P] Admin Portal: create `wallet-ecosystem/apps/admin/src/app/(dashboard)/reports/schedules/page.tsx` — schedule management page: active/inactive schedule list with columns (name, type, cadence, next run, status), "Create Schedule" button opening a side panel form (report type, filter config, cadence selector, recipient list with email opt-in checkbox per recipient), deactivate action
- [x] T078 Run PHPStan level 8 across `app/Modules/Reporting/` — `./vendor/bin/phpstan analyse app/Modules/Reporting --level=8 --configuration=phpstan.neon`; resolve all errors before marking complete
- [X] T079 Copy `specs/024-reporting-analytics/contracts/reporting.yaml` to `openapi/v1/reporting.yaml`; add `$ref` entry in the OpenAPI aggregation file (grep existing `openapi/v1/` files for the aggregation pattern used by other modules)
- [X] T080 Run quickstart.md scenarios SC-1 through SC-9 against the Docker environment; document pass/fail for each scenario as a comment on this task; all 9 scenarios must pass before marking complete

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Phase 1 — **BLOCKS all user stories**
- **US1–US4 (Phases 3–6)**: All depend on Phase 2; can proceed in parallel after Phase 2
- **US5 — Export (Phase 7)**: Depends on Phase 2; integration testing needs one complete job (US1 recommended)
- **US6 — Schedules (Phase 8)**: Depends on Phase 2 + T060 (`IncrementsFailureCounter`) which requires all 10 job handlers from Phases 3–6
- **Polish (Phase 9)**: T073/T074 require E17 module available; T075–T077 require API functional; all story phases must be complete

### User Story Dependencies

| Story | Depends on | Can parallel with |
|---|---|---|
| US1 | Phase 2 | US2, US3, US4, US5 |
| US2 | Phase 2 | US1, US3, US4, US5 |
| US3 | Phase 2 | US1, US2, US4, US5 |
| US4 | Phase 2 | US1, US2, US3, US5 |
| US5 | Phase 2 + US1 for E2E test | US2, US3, US4 |
| US6 | Phase 2 + all job handlers (T060) | Polish only |

### Within Each User Story

- Migrations → models → services → controllers → route registration → tests

---

## Parallel Execution Examples

### Phase 2 — Foundational

```
Group A (no deps):          T007, T008, T009
Group B (after T005, T006): T010, T011
Group C (independent):      T012, T013, T014
Sequential:                 T015 → T016 → T017
```

### Phase 3 — US1 (after Phase 2 complete)

```
Group A: T018, T019, T020, T021, T022, T023, T024
Group B: T025, T026, T027, T028 (after Group A models/requests done)
Final:   T029 (routes) → T030 (feature tests)
```

### Phases 4–6 — Parallel with Phase 3

```
Phase 4: T031, T032 in parallel → T033
Phase 5: T034, T035, T036 in parallel → T037 → T038
Phase 6: T039, T040 in parallel → T041
```

---

## Implementation Strategy

### MVP First (US1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (critical blocker)
3. Complete Phase 3: US1 — Transaction & Wallet Reports
4. **STOP and VALIDATE**: Run quickstart SC-1 (catalogue), SC-2 (submit + poll), SC-4 (limit), SC-5 (auth)
5. **MVP ready**: Admin Portal can submit, track, and view wallet/transaction reports

### Full Delivery Order

1. Phase 1 + Phase 2 → Foundation ready
2. Phase 3 (US1) → MVP validated
3. Phases 4, 5, 6 in parallel → all 10 report types operational
4. Phase 7 (US5 Export) → CSV/Excel downloads working
5. Phase 8 (US6 Schedules + T060 failure alerting) → Automated scheduling
6. Phase 9 (Catalogue + Admin Portal + PHPStan + Docs) → Production ready

---

## Notes

- **T018 is the reference implementation** for all 10 job handlers — implement it first, then apply the same lifecycle (Processing transition → query → Complete/Failed → events) to T019, T031, T032, T034–T037, T039, T040.
- **T060 (`IncrementsFailureCounter`)** must be applied retroactively to all job handlers once it is written — update the 10 job files by adding `use IncrementsFailureCounter;` and calling `$this->recordJobFailure()` in each catch block.
- **Cross-schema queries** use `DB::connection('platform')->table('{schema}.{table}')` only — never add Eloquent relationships across module boundaries (Constitution Principle 7).
- **`pii_access`** is stored on `ReportJobModel` at submission time; job handlers read this flag from the model, never re-evaluate permissions.
- **Financial summary balance assertion** (T040): a mismatch logs a warning but does NOT fail the job — the report is still valid and useful; the mismatch is an ops concern surfaced via `balance_validates=false` in `result_data`.
- **`cadence_day_of_month` capped at 28** to avoid month-length edge cases (no Feb 29 issues).
- Admin Portal tasks (T075–T077) require the API to be fully functional first.
