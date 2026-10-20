# Tasks — E34 QA & Validation

## Phase 1: Setup & Migrations

- [x] T001 Create PostgreSQL `qa` schema migration in `wallet-ecosystem/services/api/app/Modules/Qa/database/migrations/XXXX_create_qa_schema.php`
- [x] T002 Create `qa.test_cases` table migration with columns, indexes, and unique constraint on `code` in `wallet-ecosystem/services/api/app/Modules/Qa/database/migrations/XXXX_create_qa_test_cases_table.php`
- [x] T003 Create `qa.test_runs` table migration with columns, indexes, unique constraint on `idempotency_key`, and FK to actors in `wallet-ecosystem/services/api/app/Modules/Qa/database/migrations/XXXX_create_qa_test_runs_table.php`
- [x] T004 Create `qa.test_results` table migration with columns, FK to `qa.test_runs` and `qa.test_cases`, unique constraint on `(test_run_id, test_case_id)` in `wallet-ecosystem/services/api/app/Modules/Qa/database/migrations/XXXX_create_qa_test_results_table.php`
- [x] T005 Create `TestCaseSeeder` that inserts 144 seed records (18 categories × 8 scenario_types) with `code = qa.{category}.{scenario_type}` in `wallet-ecosystem/services/api/app/Modules/Qa/Infrastructure/Seeders/TestCaseSeeder.php`
- [x] T006 Create `QaSeedCommand` Artisan command (`qa:seed`) that calls `TestCaseSeeder` with idempotent upsert in `wallet-ecosystem/services/api/app/Modules/Qa/Console/QaSeedCommand.php`
- [x] T007 Create `QaServiceProvider` registering routes, commands, and bindings, and add it to `bootstrap/providers.php` in `wallet-ecosystem/services/api/app/Modules/Qa/QaServiceProvider.php`
- [x] T076 Create `QaEngineerSeeder` that idempotently upserts user `qa@olekard.local` / `Qa123!` with `qa.execute` permission assigned; register under `--class=QaEngineerSeeder`; required by quickstart SC-1 and SC-3 in `wallet-ecosystem/services/api/database/seeders/QaEngineerSeeder.php`
- [x] T077 Create `QaFixtureSeeder` that idempotently creates QA-namespaced fixture data: a wallet with ID stable slug `qa-test-wallet-id` (or via seeded user), fixture ledger entries summing to a known balance, and fixture identities/agents for KYC/agent executor tests; all prefixed `qa_` for easy identification and cleanup; required by financial executors (T023–T029) and quickstart SC-5 in `wallet-ecosystem/services/api/database/seeders/QaFixtureSeeder.php`

## Phase 2: Domain Enums & Models

- [x] T008 [P] Create `QaCategory` PHP 8.4 backed enum (18 string cases) with `fromSlug(string)` factory mapping URL slugs to enum values in `wallet-ecosystem/services/api/app/Modules/Qa/Domain/Enums/QaCategory.php`
- [x] T009 [P] Create `TestRunStatus` backed enum (`pending`, `running`, `completed`, `failed`) in `wallet-ecosystem/services/api/app/Modules/Qa/Domain/Enums/TestRunStatus.php`
- [x] T010 [P] Create `TestResultOutcome` backed enum (`passed`, `failed`, `skipped`, `error`) in `wallet-ecosystem/services/api/app/Modules/Qa/Domain/Enums/TestResultOutcome.php`
- [x] T011 [P] Create `ScenarioType` backed enum (8 values: `happy_path`, `validation`, `authorization`, `boundary`, `failure`, `idempotency`, `audit`, `regression`) in `wallet-ecosystem/services/api/app/Modules/Qa/Domain/Enums/ScenarioType.php`
- [x] T012 Create `TestCase` Eloquent model using UUID v7 PK, `$connection = 'platform'`, casts for `is_active` and `expected_outcome` (JSON), scoped query `active()` in `wallet-ecosystem/services/api/app/Modules/Qa/Models/TestCase.php`
- [x] T013 Create `TestRun` Eloquent model using UUID v7 PK, `$connection = 'platform'`, casts for `QaCategory` and `TestRunStatus` enums, `hasMany(TestResult::class)` in `wallet-ecosystem/services/api/app/Modules/Qa/Models/TestRun.php`
- [x] T014 Create `TestResult` Eloquent model using UUID v7 PK, `$connection = 'platform'`, casts for `TestResultOutcome` enum and `evidence` (JSON), `belongsTo(TestRun)` and `belongsTo(TestCase)` in `wallet-ecosystem/services/api/app/Modules/Qa/Models/TestResult.php`

## Phase 3: Application Layer — Core Services

- [x] T015 Create `QaExecutorInterface` contract with `execute(TestRun $run): void` signature; must not throw — all errors are caught and written as `TestResult` with outcome `error` in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/Contracts/QaExecutorInterface.php`
- [x] T016 Create `QaRunService` with `triggerRun(QaCategory $category, string $idempotencyKey, string $actorId, string $correlationId): array` that checks for existing run by idempotency key (returns `[run, false]` if found), creates `TestRun` with status `pending`, checks for a `running` run on same category (returns 409 signal), dispatches `ExecuteQaTestRunJob`, emits `QaRunRequestedEvent`, and returns `[run, true]` in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Services/QaRunService.php`
- [x] T017 Create `ExecuteQaTestRunJob` that sets `TestRun.status = running`, resolves the correct executor via a `QaExecutorRegistry`, calls `$executor->execute($run)`, then finalizes `TestRun.status = completed | failed` based on whether any `TestResult.outcome = failed | error` exists; dispatches `QaRunSucceededEvent` or `QaRunFailedEvent` on `qa` Horizon queue in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Jobs/ExecuteQaTestRunJob.php`
- [x] T018 Create `QaRunRequestedEvent`, `QaRunSucceededEvent`, `QaRunFailedEvent` implementing `IntegrationEventInterface` directly; event name format `US_QA_{NNN}_{VERB}` resolved from `QaCategory` enum in `wallet-ecosystem/services/api/app/Modules/Qa/Domain/Events/`
- [x] T019 Create `QaExecutorRegistry` that maps each `QaCategory` enum case to its concrete executor class and is registered as a singleton in `QaServiceProvider` in `wallet-ecosystem/services/api/app/Modules/Qa/Application/QaExecutorRegistry.php`

## Phase 4: Executors (18 classes)

Each executor fetches active `TestCase` records for its category, executes the assertion logic for each, and writes a `TestResult`. Financial executors wrap all DB mutations in a rolled-back transaction (no committed ledger side-effects).

- [x] T020 [P] [US1] Create `AuthUnitTestExecutor` (US-QA-001): runs PHPUnit-style assertions against auth domain models and services in-process; 8 test cases (one per scenario_type) in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/AuthUnitTestExecutor.php`
- [x] T021 [P] [US2] Create `AuthIntegrationTestExecutor` (US-QA-002): makes HTTP calls to `/v1/auth/*` with fixture credentials and asserts response contracts in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/AuthIntegrationTestExecutor.php`
- [x] T022 [P] [US3] Create `ApiAuthorizationExecutor` (US-QA-003): iterates all module route groups and verifies correct HTTP 401/403 responses for missing/wrong tokens; asserts `qa.execute` protected routes; stores endpoint-level results in evidence JSON in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/ApiAuthorizationExecutor.php`
- [x] T023 [P] [US4] Create `WalletBalanceExecutor` (US-QA-004): wraps assertions in `DB::transaction` with rollback; reads seeded QA wallet balance, asserts balance = sum of committed ledger entries, asserts no uncommitted mutations escape in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/WalletBalanceExecutor.php`
- [x] T024 [P] [US5] Create `LedgerBalanceExecutor` (US-QA-005): wraps in `DB::transaction` with rollback; verifies double-entry invariant (debits = credits) across all ledger journals for QA test wallets; records balance sheet in evidence JSON in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/LedgerBalanceExecutor.php`
- [x] T025 [P] [US6] Create `TransferExecutor` (US-QA-006): wraps in `DB::transaction` with rollback; executes transfer between QA wallets, asserts state machine transitions and ledger entries, then rollback; idempotency scenario sends same key twice in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/TransferExecutor.php`
- [x] T026 [P] [US7] Create `DuplicateTransactionExecutor` (US-QA-007): wraps in `DB::transaction` with rollback; sends two requests with identical idempotency key to each financial endpoint and asserts second returns same result without double-posting in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/DuplicateTransactionExecutor.php`
- [x] T027 [P] [US8] Create `CallbackExecutor` (US-QA-008): wraps in `DB::transaction` with rollback; simulates provider webhook delivery to `/v1/callbacks/*` endpoints, asserts state transitions and event emissions in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/CallbackExecutor.php`
- [x] T028 [P] [US9] Create `PaymentFailureExecutor` (US-QA-009): wraps in `DB::transaction` with rollback; triggers known failure scenarios (insufficient funds, gateway timeout mock, invalid account), asserts correct error codes, rollback, and no partial state in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/PaymentFailureExecutor.php`
- [x] T029 [P] [US10] Create `ReconciliationExecutor` (US-QA-010): wraps in `DB::transaction` with rollback; triggers recon cycle on QA settlement batch, asserts matched/unmatched counts, settlement amounts, and audit events in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/ReconciliationExecutor.php`
- [x] T030 [P] [US11] Create `KycExecutor` (US-QA-011): submits KYC documents for fixture identity, asserts tier promotion, audit event, and admin approval workflow; does not mutate production identity data in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/KycExecutor.php`
- [x] T031 [P] [US12] Create `AgentExecutor` (US-QA-012): registers fixture agent, asserts approval flow, float assignment, commission eligibility, and status transitions in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/AgentExecutor.php`
- [x] T032 [P] [US13] Create `SecurityExecutor` (US-QA-013): runs OWASP Top 10 pattern assertions: SQL injection probe on query-param endpoints (expect 400, not 500), auth bypass on protected routes (expect 401/403), rate-limit header present on auth endpoints, response headers include `X-Content-Type-Options`, `X-Frame-Options`; categorize results as `passed`/`advisory`/`failed`; note: no active penetration in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/SecurityExecutor.php`
- [x] T033 [P] [US14] Create `PerformanceExecutor` (US-QA-014): runs 100 sequential timed `app()->call()` dispatches against 5 key endpoints (login, wallet balance, transfer initiation, transaction list, dashboard); computes p50/p95/p99; stores latency histogram in `TestResult.evidence`; fails if p95 exceeds `config('qa.thresholds.performance_p95_ms', 500)` milliseconds in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/PerformanceExecutor.php`
- [x] T034 [P] [US15] Create `LoadExecutor` (US-QA-015): dispatches `config('qa.thresholds.load_concurrency', 50)` concurrent `app()->call()` fibers targeting the transfer endpoint; asserts all return success or known conflict (no 500s); stores throughput in `TestResult.evidence` in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/LoadExecutor.php`
- [x] T035 [P] [US16] Create `DisasterRecoveryExecutor` (US-QA-016): reads `config('qa.dr_simulation_enabled', false)`; when `false` runs readiness assertions using config-driven bounds — DB replica lag < `config('qa.thresholds.dr_replica_lag_seconds', 30)`, Horizon queue depth < `config('qa.thresholds.dr_horizon_queue_depth', 500)`, backup recency < `config('qa.thresholds.dr_backup_max_age_hours', 24)` hours — Redis cluster health check; when `true` triggers configurable DR simulation steps in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/DisasterRecoveryExecutor.php`
- [x] T036 [P] [US17] Create `UatExecutor` (US-QA-017): runs curated acceptance scenarios from each prior epic's quickstart SC-1 (login, KYC tier 1, wallet balance, fund wallet, transfer, withdrawal, merchant payment); stores per-scenario pass/fail in evidence; test case code format `uat.{epic}.sc1` in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/UatExecutor.php`
- [x] T037 [P] [US18] Create `RegressionExecutor` (US-QA-018): runs a subset of the full module test suite (PHPUnit in-process via `TestRunner` facade) against E01–E33 critical paths; aggregates pass/fail counts; marks as `failed` if any critical-path test regresses in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Executors/RegressionExecutor.php`

## Phase 5: HTTP Layer

- [x] T038 Create `TriggerQaRunRequest` form request validating that `Idempotency-Key` header is present (UUID format); resolves `QaCategory` from route `{category}` segment in `wallet-ecosystem/services/api/app/Modules/Qa/Http/Requests/TriggerQaRunRequest.php`
- [x] T039 Create `QaTriggerController` with single `trigger(string $category, TriggerQaRunRequest $request)` action that calls `QaRunService`, returns 202 with `TestRunResource` for new runs or 200 for idempotent duplicates, and 409 for running-category conflict in `wallet-ecosystem/services/api/app/Modules/Qa/Http/Controllers/QaTriggerController.php`
- [x] T040 Create `QaRunController` with `index(Request)` (paginated list with optional `category`/`status` filters) and `show(string $id)` (single run with results) actions in `wallet-ecosystem/services/api/app/Modules/Qa/Http/Controllers/QaRunController.php`
- [x] T041 Create `QaDashboardController` with `index()` action returning `DashboardResource` (latest run per category, aggregate summary) in `wallet-ecosystem/services/api/app/Modules/Qa/Http/Controllers/QaDashboardController.php`
- [x] T042 [P] Create `TestRunResource` (list item: id, category, status, triggered_by, passed/failed/skipped/total counts, started_at, completed_at, created_at) in `wallet-ecosystem/services/api/app/Modules/Qa/Http/Resources/TestRunResource.php`
- [x] T043 [P] Create `TestRunDetailResource` (all `TestRunResource` fields + nested `results` array with test_case code/name/scenario_type, outcome, duration_ms, error_message, evidence) in `wallet-ecosystem/services/api/app/Modules/Qa/Http/Resources/TestRunDetailResource.php`
- [x] T044 [P] Create `DashboardResource` (summary object + categories array with story label, latest_run_id, latest_status, passed/failed/total, last_run_at) in `wallet-ecosystem/services/api/app/Modules/Qa/Http/Resources/DashboardResource.php`
- [x] T045 Register `routes.php` under `wallet-ecosystem/services/api/app/Modules/Qa/` with middleware `['identity.auth', AddCorrelationIdHeader::class]`, prefix `/v1/qa`; register all 18 named trigger routes + GET `/runs`, GET `/runs/{id}`, GET `/dashboard` in `wallet-ecosystem/services/api/app/Modules/Qa/routes.php`
- [x] T046 Add Horizon `qa` queue configuration with `tries = 3`, `backoff = [10, 60]`, and `timeout = 300` in `wallet-ecosystem/services/api/config/horizon.php`

## Phase 6: Events & Outbox

- [x] T047 Emit `QaRunRequestedEvent` in `QaRunService::triggerRun()` after `TestRun` is created; emit `AUDIT_EVENT_RECORDED` via `OutboxEventPublisher`; include `run_id`, `category`, `actor_id`, `correlation_id` in payload in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Services/QaRunService.php`
- [x] T048 Emit `QaRunSucceededEvent` or `QaRunFailedEvent` in `ExecuteQaTestRunJob::handle()` after `TestRun.status` is finalized; emit via `OutboxEventPublisher` in `wallet-ecosystem/services/api/app/Modules/Qa/Application/Jobs/ExecuteQaTestRunJob.php`
- [x] T049 Register QA events in `QaServiceProvider` `$listen` array and ensure `OutboxEventPublisher` handles `QaRunRequestedEvent`/`QaRunSucceededEvent`/`QaRunFailedEvent` in `wallet-ecosystem/services/api/app/Modules/Qa/QaServiceProvider.php`

## Phase 7: OpenAPI

- [x] T050 Write `openapi/v1/qa.yaml` documenting all 21 endpoints (18 trigger POSTs + `GET /runs` + `GET /runs/{id}` + `GET /dashboard`) with request/response schemas, error codes `QA_UNAUTHORIZED`, `QA_FORBIDDEN`, `QA_IDEM_REQUIRED`, `QA_CATEGORY_RUNNING`, `QA_RUN_NOT_FOUND` in `wallet-ecosystem/services/api/openapi/v1/qa.yaml`

## Phase 8: Frontend

- [x] T051 Add QA API methods to `AdminAppClient`: `triggerQaRun(category, idempotencyKey)`, `listQaRuns(params?)`, `getQaRun(id)`, `getQaDashboard()` in `wallet-ecosystem/apps/admin/lib/api/admin-app-client.ts`
- [x] T052 Create `src/modules/qa/api/qa.ts` as thin module wrapper over `AdminAppClient` for QA methods; re-export `TestRun`, `TestResult`, `DashboardData` types in `wallet-ecosystem/apps/admin/src/modules/qa/api/qa.ts`
- [x] T053 Create `QaDashboardPage` component: 18-category grid with status badge (PASSED/FAILED/RUNNING/PENDING/NEVER RUN), last-run timestamp, "Run Now" button per category with inline confirmation; aggregate summary header in `wallet-ecosystem/apps/admin/src/modules/qa/pages/qa-dashboard.tsx`
- [x] T054 Create `QaRunDetailPage` component: run metadata header (category, status, triggered_by, duration), results table (test_case name/scenario_type, outcome badge, duration_ms, error_message), evidence JSON expandable panel in `wallet-ecosystem/apps/admin/src/modules/qa/pages/qa-run-detail.tsx`
- [x] T055 Create `app/(admin)/qa/page.tsx` Next.js route file with Metadata title "QA Dashboard — OleKard Admin", rendering `QaDashboardPage` in `wallet-ecosystem/apps/admin/app/(admin)/qa/page.tsx`
- [x] T056 Create `app/(admin)/qa/runs/[id]/page.tsx` Next.js route file with Metadata title "QA Run Detail — OleKard Admin", rendering `QaRunDetailPage` with `params.id` in `wallet-ecosystem/apps/admin/app/(admin)/qa/runs/[id]/page.tsx`
- [x] T057 Add `/qa` link to admin sidebar navigation under the "Quality" or "System" section in `wallet-ecosystem/apps/admin/src/components/layout/` (locate the sidebar component and add the nav entry)
- [x] T058 Gate `/qa/*` routes with `qa.execute` permission check in Next.js middleware or layout in `wallet-ecosystem/apps/admin/middleware.ts` (or the layout-level auth check file)

## Phase 9: Tests

- [x] T059 [P] Create `QaTriggerTest.php` feature tests: (a) happy path → 202 + run_id; (b) no auth → 401; (c) missing `qa.execute` → 403; (d) missing `Idempotency-Key` → 422; (e) duplicate key → 200 + same run_id; (f) category already running → 409 in `wallet-ecosystem/services/api/tests/Feature/Qa/QaTriggerTest.php`
- [x] T060 [P] Create `QaRunTest.php` feature tests: (a) list returns paginated runs; (b) list filtered by category; (c) detail returns run + results; (d) unknown run id → 404 in `wallet-ecosystem/services/api/tests/Feature/Qa/QaRunTest.php`
- [x] T061 [P] Create `QaDashboardTest.php` feature tests: (a) returns 18 categories; (b) untriggered category shows null latest_status; (c) completed run appears in category row in `wallet-ecosystem/services/api/tests/Feature/Qa/QaDashboardTest.php`
- [x] T062 [P] Create `AuthUnitTestExecutorTest.php` unit tests: executor writes 8 TestResult records; happy_path result = passed; audit result present in `wallet-ecosystem/services/api/tests/Unit/Qa/AuthUnitTestExecutorTest.php`
- [x] T063 [P] Create `WalletBalanceExecutorTest.php` unit tests: balance assertion passes; no wallet balance committed to DB after run; TestRun.status = completed in `wallet-ecosystem/services/api/tests/Unit/Qa/WalletBalanceExecutorTest.php`
- [x] T064 [P] Create `DuplicateTransactionExecutorTest.php` unit tests: second identical idempotency key returns same response; no double-posting to ledger in `wallet-ecosystem/services/api/tests/Unit/Qa/DuplicateTransactionExecutorTest.php`
- [x] T065 [P] Create `SecurityExecutorTest.php` unit tests: OWASP checks produce TestResult records; SQL injection probe returns `passed` (no 500); missing auth returns `passed` (403 received) in `wallet-ecosystem/services/api/tests/Unit/Qa/SecurityExecutorTest.php`
- [x] T066 [P] Create `DisasterRecoveryExecutorTest.php` unit tests: config=false runs readiness assertions only; config=true runs simulation steps in `wallet-ecosystem/services/api/tests/Unit/Qa/DisasterRecoveryExecutorTest.php`
- [x] T067 [P] Create `PerformanceExecutorTest.php` unit tests: 100 iterations run; evidence JSON has p50/p95/p99 keys; fails outcome when threshold exceeded in `wallet-ecosystem/services/api/tests/Unit/Qa/PerformanceExecutorTest.php`
- [x] T068 [P] Create `UatExecutorTest.php` unit tests: at least 5 cross-epic scenarios run; each produces a TestResult; failed scenario marks run as failed in `wallet-ecosystem/services/api/tests/Unit/Qa/UatExecutorTest.php`
- [x] T069 Run PHPStan level 8 on `app/Modules/Qa/` — must produce 0 errors (Docker-gated)
- [X] T070 Run quickstart SC-1 through SC-6 against Docker dev stack and attach pass evidence (Docker-gated)
- [x] T078 [P] Audit `qa-dashboard.tsx` and `qa-run-detail.tsx` for Principle 11 (Accessibility): add `role="status"` and `aria-label` to outcome badge components (PASSED/FAILED/RUNNING), ensure "Run Now" confirmation controls are keyboard-focusable with visible focus ring, add `aria-live="polite"` region for run-status updates, verify color contrast on badge variants meets WCAG AA in `wallet-ecosystem/apps/admin/src/modules/qa/pages/qa-dashboard.tsx` and `wallet-ecosystem/apps/admin/src/modules/qa/pages/qa-run-detail.tsx`

## Phase 10: Documentation & Release

- [x] T071 [P] Register `openapi/v1/qa.yaml` in the OpenAPI index/aggregator (if one exists, e.g. `openapi/v1/openapi.yaml` paths reference) in `wallet-ecosystem/services/api/openapi/v1/`
- [x] T072 [P] Create `config/qa.php` with a full thresholds section and document all keys in `.env.example`; required structure: `['dr_simulation_enabled' => false, 'thresholds' => ['performance_p95_ms' => 500, 'load_concurrency' => 50, 'dr_replica_lag_seconds' => 30, 'dr_horizon_queue_depth' => 500, 'dr_backup_max_age_hours' => 24]]`; each value overridable via `QA_*` env vars so CI environments can tune without code changes in `wallet-ecosystem/services/api/config/qa.php`
- [x] T073 Run `/speckit-analyze` on E34 artifacts
- [x] T074 Run `/speckit-converge` after implementation to confirm no spec gaps remain
- [x] T075 Mark E34 RELEASE READY in memory after SC-1–SC-6 pass and T069 PHPStan clean

## Phase 11: Convergence

- [x] T079 Add `LedgerBalanceExecutorTest.php` unit test: executor writes results for all active cases; no committed DB mutations to `ledger.journal_lines` after execute() returns; executor does not throw per US-QA-005/Constitution (missing) in `wallet-ecosystem/services/api/tests/Unit/Qa/LedgerBalanceExecutorTest.php`
- [x] T080 Add `TransferExecutorTest.php` unit test: executor writes results; no committed ledger mutations after execute() returns; idempotency result present per US-QA-006/Constitution (missing) in `wallet-ecosystem/services/api/tests/Unit/Qa/TransferExecutorTest.php`
- [x] T081 Add `CallbackExecutorTest.php` unit test: callback simulation produces test results; no committed state mutations in `wallet.*` tables after execute() returns; executor does not throw per US-QA-008/Constitution (missing) in `wallet-ecosystem/services/api/tests/Unit/Qa/CallbackExecutorTest.php`
- [x] T082 Add `PaymentFailureExecutorTest.php` unit test: failure scenarios produce results with failure/error outcomes; no committed partial state after execute() returns; executor does not throw per US-QA-009/Constitution (missing) in `wallet-ecosystem/services/api/tests/Unit/Qa/PaymentFailureExecutorTest.php`
- [x] T083 Add `ReconciliationExecutorTest.php` unit test: recon cycle produces matched/unmatched result records; no committed mutations to `reconciliation.*` tables after execute() returns; executor does not throw per US-QA-010/Constitution (missing) in `wallet-ecosystem/services/api/tests/Unit/Qa/ReconciliationExecutorTest.php`
- [x] T084 Add `AddCorrelationIdHeader::class` to middleware array in `routes/modules/qa.php` so `X-Correlation-Id` is echoed in QA API response headers per plan: T045 Observability (partial) in `wallet-ecosystem/services/api/routes/modules/qa.php`

## Dependencies

```
Phase 1 (T001–T007, T076–T077) must complete before Phase 2
Phase 2 (T008–T014) must complete before Phase 3
Phase 3 core (T015–T019) must complete before Phase 4 executors (T020–T037)
Phase 4 (T020–T037) can run fully in parallel with each other
Phase 4 must complete before Phase 5 HTTP layer
Phase 5 (T038–T046) must complete before Phase 6 and Phase 7
T046 (Horizon qa queue config) must complete before T017 (ExecuteQaTestRunJob)
T072 (config/qa.php) must complete before T033, T034, T035 (threshold-reading executors)
T077 (QaFixtureSeeder) must complete before T023–T029 (financial executors) and T070
T076 (QaEngineerSeeder) must complete before T070 (quickstart SC validation)
Phase 6 (T047–T049) can run in parallel with Phase 7 (T050) and Phase 8 (T051–T058)
Phase 9 tests (T059–T068, T078) require Phase 5+6 complete; individual tests [P] run in parallel
T069–T070 are Docker-gated; defer until Docker stack available
```

## Parallel Execution Examples

**Within Phase 4 (Executors)**: T020–T037 are fully independent and can run simultaneously.
**Within Phase 9 (Tests)**: T059–T068 are fully independent and can run simultaneously.
**Phase 6 + 7 + 8**: Can start in parallel after Phase 5 completes.
