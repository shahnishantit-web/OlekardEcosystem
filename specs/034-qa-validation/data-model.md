# Data Model — E34 QA & Validation

## Schema

All tables reside in the `qa` PostgreSQL schema (schema-per-module; no foreign keys to other module schemas).

---

## Entity: TestCase

Represents a single executable test scenario belonging to one QA category.

**Table**: `qa.test_cases`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `uuid` | PK, not null | UUID v7 |
| `code` | `varchar(120)` | UNIQUE, not null | Dot-separated slug, e.g. `qa.unit.auth.login` |
| `category` | `varchar(60)` | not null | See `QaCategory` enum |
| `name` | `varchar(255)` | not null | Human-readable test name |
| `description` | `text` | nullable | Longer description |
| `scenario_type` | `varchar(40)` | not null | See `ScenarioType` enum |
| `expected_outcome` | `jsonb` | nullable | Assertion metadata |
| `is_active` | `boolean` | not null, default true | Inactive cases are skipped in runs |
| `created_at` | `timestamptz` | not null | |
| `updated_at` | `timestamptz` | not null | |

**Indexes**: `idx_qa_test_cases_category` on `(category)`, `idx_qa_test_cases_active` on `(is_active)` where `is_active = true`.

---

## Entity: TestRun

Represents a single execution of all `TestCase` records belonging to one `QaCategory`.

**Table**: `qa.test_runs`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `uuid` | PK, not null | UUID v7 |
| `category` | `varchar(60)` | not null | Matches `TestCase.category`; one run covers all cases in this category |
| `triggered_by` | `uuid` | not null | Actor (user) who triggered the run |
| `status` | `varchar(20)` | not null, default `'pending'` | See `TestRunStatus` enum |
| `idempotency_key` | `varchar(255)` | UNIQUE, not null | From `Idempotency-Key` request header |
| `correlation_id` | `varchar(36)` | not null | For log tracing |
| `started_at` | `timestamptz` | nullable | Set when job begins execution |
| `completed_at` | `timestamptz` | nullable | Set when job finishes (success or fail) |
| `created_at` | `timestamptz` | not null | |

**Indexes**: `idx_qa_test_runs_category_status` on `(category, status)`, `idx_qa_test_runs_triggered_by` on `(triggered_by)`, `idx_qa_test_runs_created_at` on `(created_at DESC)`.

---

## Entity: TestResult

Represents the outcome of a single `TestCase` within a `TestRun`.

**Table**: `qa.test_results`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | `uuid` | PK, not null | UUID v7 |
| `test_run_id` | `uuid` | not null, FK → `qa.test_runs(id)` ON DELETE CASCADE | |
| `test_case_id` | `uuid` | not null, FK → `qa.test_cases(id)` | |
| `outcome` | `varchar(20)` | not null | See `TestResultOutcome` enum |
| `duration_ms` | `integer` | nullable | Execution time in milliseconds |
| `error_message` | `text` | nullable | Non-null when outcome = `failed` or `error` |
| `evidence` | `jsonb` | nullable | Structured proof data (request/response excerpts, metric snapshots) |
| `created_at` | `timestamptz` | not null | |

**Indexes**: `idx_qa_test_results_run_id` on `(test_run_id)`, `idx_qa_test_results_outcome` on `(test_run_id, outcome)`.

**Unique constraint**: `uq_qa_test_results_run_case` on `(test_run_id, test_case_id)` — one result per case per run.

---

## Enums

### QaCategory (18 values)

| Value | Story |
|-------|-------|
| `unit_auth` | US-QA-001 |
| `integration_auth` | US-QA-002 |
| `api_authorization` | US-QA-003 |
| `wallet_balance` | US-QA-004 |
| `ledger_balance` | US-QA-005 |
| `transfer` | US-QA-006 |
| `duplicate_transaction` | US-QA-007 |
| `callback` | US-QA-008 |
| `payment_failure` | US-QA-009 |
| `reconciliation` | US-QA-010 |
| `kyc` | US-QA-011 |
| `agent` | US-QA-012 |
| `security` | US-QA-013 |
| `performance` | US-QA-014 |
| `load` | US-QA-015 |
| `disaster_recovery` | US-QA-016 |
| `uat` | US-QA-017 |
| `regression` | US-QA-018 |

### ScenarioType (8 values)

| Value | Maps to |
|-------|---------|
| `happy_path` | QA Scenario 1 |
| `validation` | QA Scenario 2 |
| `authorization` | QA Scenario 3 |
| `boundary` | QA Scenario 4 |
| `failure` | QA Scenario 5 |
| `idempotency` | QA Scenario 6 |
| `audit` | QA Scenario 7 |
| `regression` | QA Scenario 8 |

### TestRunStatus (4 values)

`pending` → `running` → `completed` | `failed`

### TestResultOutcome (4 values)

`passed`, `failed`, `skipped`, `error`

---

## Relationships

```
TestCase ─── (many) ──→ TestResult ──→ TestRun
              category links TestCase to TestRun indirectly
              one TestRun covers all active TestCases with matching category
```

---

## Seeding

`TestCaseSeeder` inserts one `TestCase` record per (category × scenario_type) combination = 18 × 8 = 144 seed records. Each `code` follows the pattern `qa.{category}.{scenario_type}` (e.g. `qa.unit_auth.happy_path`).

Financial test cases (categories: `wallet_balance`, `ledger_balance`, `transfer`, `duplicate_transaction`, `callback`, `payment_failure`, `reconciliation`) store their expected assertion spec in `expected_outcome` JSON so the executor can be data-driven.
