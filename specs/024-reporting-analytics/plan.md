# Implementation Plan — E24 Reporting & Analytics

## Constitution Check

- [x] Approved Laravel/Next.js/Flutter stack respected — Backend: Laravel/PHP 8.4+; Admin UI: Next.js/TypeScript; Flutter: explicitly out of scope per spec
- [x] No TBD value invented — configurable defaults (max rows, timeout, concurrent limit, retention) are seeded values, not hard-coded business decisions
- [x] Authorization remains server-side — permission checked in every controller/service; gated by `admin.read`, `finance.reports`, `compliance.reports`, `reports.schedule`, `compliance.pii.read`
- [x] Financial integrity preserved — Reporting is read-only over existing domain data; financial summary reports validate totals against source ledger; no ledger mutations
- [x] Security/audit requirements incorporated — every report request, job status transition, export generation, and schedule change produces an audit event via the AuditModuleContract; download links are time-limited and authenticated
- [x] UX/design-system requirements incorporated — Admin Portal uses shared design tokens; report status polling, download flows, and schedule management follow existing Admin UI patterns
- [x] QA strategy incorporated — unit tests for services/jobs; feature tests for all API endpoints; concurrency test for per-user job limit; financial validation test for summary report; PII masking test

## Technical Context

- Backend: Laravel / PHP 8.4+, modular monolith under `App\Modules\Reporting`
- Database: PostgreSQL — new `reporting` schema; cross-schema read queries against `wallet`, `ledger`, `transaction`, `kyc`, `risk`, `agent`, `audit`, `reconciliation` schemas (same DB connection, no joins across module boundaries — raw SQL aggregates via `DB::connection('platform')`)
- Queue/Cache: Redis + Laravel Horizon — report jobs and export jobs dispatched to `reports` queue; per-user job limit enforced via DB count (not Redis); ops alert threshold via Redis sliding-window counter
- Web: Next.js + TypeScript — Admin Portal; new `/admin/reports` section
- Mobile: Flutter — out of scope
- API: REST/OpenAPI — new route file `routes/modules/reporting.php`; `ReportingServiceProvider` boots routes and scheduler
- File Storage: Laravel Storage (`storage` disk — S3-compatible in production, local in dev); `Storage::temporaryUrl()` for pre-signed download links; fallback signed URL for local driver
- Testing: PHPUnit/Pest; feature tests for all API endpoints; unit tests for application services and jobs; concurrency test for limit enforcement
- Observability: structured logs and metrics on job lifecycle events; Redis sliding-window counter per report type for ops alerting; correlation IDs on all job dispatches

## Dependencies on Prior Epics

| Module | Usage |
|---|---|
| E01 Identity | Authentication middleware (`identity.auth`), permission middleware (`identity.require-permission`) |
| E04 Roles & Permissions | Permissions: `admin.read`, `finance.reports`, `compliance.reports`, `reports.schedule`, `compliance.pii.read` — must be seeded |
| E07 Ledger | Financial summary queries against `ledger.journal_entries` |
| E09 Transfer / E08 Funding / E10 Withdrawal | Transaction volume data for transaction and financial summary reports |
| E12 Agent | Agent data for agent reports |
| E14 Commission | Commission records for commission reports |
| E05 KYC | KYC records for KYC reports |
| E18 Risk/AML | Risk flags for risk reports |
| E20 Reconciliation | Reconciliation and settlement data for those reports |
| E17 Notifications | In-app and email notifications for scheduled report completion/failure |
| E23 Audit | Audit event recording via `AuditModuleContract` |
| E28 Configuration | Configurable defaults: max rows, timeout, concurrent limit, retention periods, alert thresholds |

## Phase 0 — Research

All technical unknowns resolved. See `research.md`.

## Phase 1 — Design

Artifacts produced:
- `data-model.md`
- `contracts/reporting.yaml`
- `quickstart.md`

## Implementation Approach

1. Create `reporting` PostgreSQL schema (migration).
2. Create `ReportDefinition` seeder (10 report types, permissions, filter schemas).
3. Create `ReportJob` model, repository, and application service — job lifecycle management.
4. Implement per-report-type job handlers (10 jobs, one per report type) that execute live queries and store results as JSONB on the job record.
5. Implement export job (separate queue job) — streams `ReportJob` result to CSV or Excel file, writes to storage, creates `ReportExport` record.
6. Implement `ReportSchedule` CRUD + `schedule_runs` tracking + `reports:process-schedules` Artisan command.
7. Implement HTTP layer: controllers, requests, resources for jobs, exports, schedules, catalogue.
8. Register routes, service provider, scheduler command.
9. Implement Admin Portal pages: report list, job status, export download, schedule management.
10. Add tests: unit (services/jobs), feature (API), concurrency (job limit), financial validation (summary).
11. Write OpenAPI documentation.

## Data / Financial Controls

- Reporting is strictly read-only; no financial mutations.
- Financial summary report includes a validation assertion: `sum(inflows) - sum(outflows) - sum(fees) == reported net position` (within rounding tolerance per currency); computed at query time and stored on the job record.
- Cross-currency aggregation is prohibited; all financial aggregates are grouped by currency.
- Historical ledger/transaction records are never modified or deleted by this module.

## Observability

- Every `ReportJob` state transition emits a structured log entry with `job_id`, `report_type`, `status`, `user_id`, `correlation_id`, `duration_ms`.
- Every `ReportExport` creation emits a structured log entry with `export_id`, `job_id`, `format`, `user_id`, `correlation_id`.
- Redis sliding-window counter (key: `reports:failure_rate:{type}:{window_start}`) incremented on each job failure; checked against configurable threshold; `ReportFailureThresholdExceededEvent` dispatched when threshold crossed.
- Metrics emitted: `reporting.job.submitted`, `reporting.job.completed`, `reporting.job.failed`, `reporting.export.generated`, `reporting.schedule.triggered`.

## Complexity Tracking

Any change to the approved read-only cross-schema query approach (e.g., introducing a reporting-specific materialized view or data warehouse) requires an ADR and constitution check update.
