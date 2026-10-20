# Implementation Plan — E34 QA & Validation

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — QA module uses Laravel/PHP 8.4, Next.js for admin UI, no new tech
- [x] No TBD value invented — provider TBDs in spec remain TBD; DR simulation is config-gated
- [x] Authorization remains server-side — all endpoints gated by `qa.execute` permission check via `identity.auth` middleware
- [x] Financial integrity preserved — financial executors use `DatabaseTransactions` rollback; no real ledger mutations; Principle 4 maintained
- [x] Security/audit requirements incorporated — every run emits `AUDIT_EVENT_RECORDED` via outbox; correlation IDs on all requests
- [x] UX/design-system requirements incorporated — admin QA dashboard and run-detail pages use shared design tokens/components
- [x] QA strategy incorporated — each executor implements all 8 scenario types per Principle 12; 144 seed test cases

---

## Technical Context

| Concern | Technology |
|---------|------------|
| Backend | Laravel 11 / PHP 8.4+, modular monolith pattern |
| Module path | `wallet-ecosystem/services/api/app/Modules/Qa/` |
| DB | PostgreSQL schema `qa` (schema-per-module) |
| Queue | Redis + Horizon, dedicated `qa` queue |
| Frontend | Next.js 14 + TypeScript (admin portal) |
| Frontend path | `wallet-ecosystem/apps/admin/` |
| API | REST, `/v1/qa/` prefix, OpenAPI documented |
| Tests | PHPUnit/Pest feature tests; PHPStan level 8 |
| Observability | Structured logging + `X-Correlation-Id` + `AUDIT_EVENT_RECORDED` |

---

## Architecture Overview

```
HTTP Request
    │
    ▼
QaTriggerController (thin)
    │   validates Idempotency-Key + qa.execute
    ▼
QaRunService
    │   creates TestRun (status: pending)
    │   dispatches ExecuteQaTestRunJob → Horizon 'qa' queue
    │   emits US_QA_{NNN}_REQUESTED
    ▼
202 Accepted { run_id }

--- async ---

ExecuteQaTestRunJob
    │   updates TestRun.status = running
    │   resolves executor from QaCategory enum
    ▼
{Category}Executor
    │   fetches active TestCases for category
    │   runs each TestCase, writes TestResult
    │   financial cases: wrapped in DB transaction (auto-rollback)
    ▼
TestResult persisted (passed | failed | skipped | error)
    │
    ▼
QaRunService finalizes TestRun.status = completed | failed
    │
    ▼
emits US_QA_{NNN}_SUCCEEDED | US_QA_{NNN}_FAILED
emits AUDIT_EVENT_RECORDED (via OutboxEventPublisher)
```

---

## Backend Module Structure

```
wallet-ecosystem/services/api/app/Modules/Qa/
├── QaServiceProvider.php
├── routes.php
├── Domain/
│   └── Enums/
│       ├── QaCategory.php           (18-value backed enum)
│       ├── TestRunStatus.php        (pending|running|completed|failed)
│       ├── TestResultOutcome.php    (passed|failed|skipped|error)
│       └── ScenarioType.php         (8-value backed enum)
├── Models/
│   ├── TestCase.php
│   ├── TestRun.php
│   └── TestResult.php
├── Application/
│   ├── Services/
│   │   └── QaRunService.php
│   ├── Jobs/
│   │   └── ExecuteQaTestRunJob.php
│   └── Executors/
│       ├── Contracts/
│       │   └── QaExecutorInterface.php
│       ├── AuthUnitTestExecutor.php
│       ├── AuthIntegrationTestExecutor.php
│       ├── ApiAuthorizationExecutor.php
│       ├── WalletBalanceExecutor.php
│       ├── LedgerBalanceExecutor.php
│       ├── TransferExecutor.php
│       ├── DuplicateTransactionExecutor.php
│       ├── CallbackExecutor.php
│       ├── PaymentFailureExecutor.php
│       ├── ReconciliationExecutor.php
│       ├── KycExecutor.php
│       ├── AgentExecutor.php
│       ├── SecurityExecutor.php
│       ├── PerformanceExecutor.php
│       ├── LoadExecutor.php
│       ├── DisasterRecoveryExecutor.php
│       ├── UatExecutor.php
│       └── RegressionExecutor.php
├── Events/
│   ├── QaRunRequestedEvent.php
│   ├── QaRunSucceededEvent.php
│   └── QaRunFailedEvent.php
├── Http/
│   ├── Controllers/
│   │   ├── QaTriggerController.php
│   │   ├── QaRunController.php
│   │   └── QaDashboardController.php
│   ├── Requests/
│   │   └── TriggerQaRunRequest.php
│   └── Resources/
│       ├── TestRunResource.php
│       ├── TestRunDetailResource.php
│       └── DashboardResource.php
└── database/
    ├── migrations/
    │   ├── ..._create_qa_schema.php
    │   ├── ..._create_qa_test_cases_table.php
    │   ├── ..._create_qa_test_runs_table.php
    │   └── ..._create_qa_test_results_table.php
    └── seeders/
        └── TestCaseSeeder.php
```

---

## Frontend Structure

```
wallet-ecosystem/apps/admin/
├── app/(admin)/qa/
│   ├── page.tsx                          (QA dashboard → QaDashboardPage component)
│   └── runs/
│       └── [id]/page.tsx                 (Run detail → QaRunDetailPage component)
└── src/modules/qa/
    ├── api/
    │   └── qa.ts                         (thin wrapper over AdminAppClient for QA endpoints)
    └── pages/
        ├── qa-dashboard.tsx
        └── qa-run-detail.tsx
```

---

## OpenAPI

```
wallet-ecosystem/services/api/openapi/v1/qa.yaml
```

21 endpoints total: 18 trigger POSTs + `GET /runs` + `GET /runs/{id}` + `GET /dashboard`.

---

## Key Decisions

1. **Executor pattern**: One executor class per `QaCategory` (not a mega-switch) — keeps test logic isolated and extraction-ready per Principle 7.

2. **`QaTriggerController` route resolution**: A single controller with a `trigger(string $category)` action resolves the category slug from the URL segment. `routes.php` registers 18 named routes pointing to this single action, keeping the router thin.

3. **Financial executor isolation**: `WalletBalanceExecutor`, `LedgerBalanceExecutor`, `TransferExecutor`, `DuplicateTransactionExecutor`, `CallbackExecutor`, `PaymentFailureExecutor`, `ReconciliationExecutor` each wrap assertion logic in `DB::transaction(fn() => ..., /* rollback on assert failure */); ` — no real mutations reach committed state.

4. **DR test guard**: `DisasterRecoveryExecutor` reads `config('qa.dr_simulation_enabled', false)`. When `false` (default), it runs readiness assertions only. This satisfies Principle 2 (no TBD business value invented).

5. **Performance/Load executors**: `PerformanceExecutor` (US-QA-014) uses sequential timed HTTP calls via Laravel's internal `app()->call()` dispatch (no real HTTP round-trip in dev). `LoadExecutor` (US-QA-015) uses PHP Fibers for 50 concurrent dispatches. Both write latency metrics into `TestResult.evidence`.

6. **Idempotency on trigger endpoints**: `QaRunService` checks `TestRun::where('idempotency_key', $key)->first()` — if found, returns existing record (HTTP 200 via controller); if not found, creates new record + dispatches job (HTTP 202).

7. **409 Conflict**: If a run for the same category is already `running`, return `409 QA_CATEGORY_RUNNING` rather than creating a second parallel run.

8. **Events**: `QaRunRequestedEvent`, `QaRunSucceededEvent`, `QaRunFailedEvent` extend `BaseIntegrationEvent` and are published via `OutboxEventPublisher` (consistent with E17/E22/E23 pattern). Event name format: `US_QA_{NNN}_{VERB}` (e.g. `US_QA_001_REQUESTED`).

---

## Phase 0 — Research

Complete. See `research.md` for all 7 decisions resolved.

---

## Phase 1 — Design

Complete. Generated:
- `data-model.md` — TestCase, TestRun, TestResult entities (3 tables in `qa` schema)
- `contracts/qa.md` — 21 REST endpoints with request/response shapes and error codes
- `quickstart.md` — SC-1 through SC-6 validation scenarios

---

## Implementation Approach

1. Migrations + schema + seeder (foundation)
2. Enums + Models (domain primitives)
3. `QaRunService` + `QaRunInterface` (application gate)
4. `ExecuteQaTestRunJob` dispatcher
5. All 18 executor classes (with interface)
6. Domain events + OutboxEventPublisher wiring
7. HTTP layer (controller, requests, resources, routes)
8. OpenAPI yaml
9. Frontend: QA api module + dashboard + run-detail pages
10. Feature tests (trigger, idempotency, auth, conflict, dashboard)
11. PHPStan level 8 clean pass

---

## Data / Financial Controls

- Financial executor test cases wrapped in auto-rollback DB transactions (no committed ledger mutations)
- `TestRun.idempotency_key` has UNIQUE constraint (duplicate run prevention at DB level)
- No direct wallet balance mutations in any executor
- Concurrency tested: duplicate `Idempotency-Key` race handled by unique constraint + service-level check

## Observability

- `X-Correlation-Id` stored on `TestRun.correlation_id`
- All trigger endpoints emit `AUDIT_EVENT_RECORDED` via outbox after run creation
- Horizon queue `qa` monitored separately from financial queues
- `TestResult.duration_ms` enables latency regression detection across runs

## Complexity Tracking

No architecture deviation from approved stack. No ADR required. Any future addition of external load-testing tools (k6) would require an ADR under Principle 14.
