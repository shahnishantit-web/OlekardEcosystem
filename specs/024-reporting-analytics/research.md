# Research — E24 Reporting & Analytics

## 1. Async Report Job Pattern

**Decision**: Follow the existing `GenerateComplianceReportJob` pattern from the Audit module (`App\Modules\Audit\Infrastructure\Jobs\GenerateComplianceReportJob`).

**Rationale**: The pattern is already proven in the codebase — a `ShouldQueue` job uses `Dispatchable`, `InteractsWithQueue`, `Queueable`, `SerializesModels`, updates a model's status to `processing`, executes the work, then transitions to `complete` or `failed`. E24 extends this with a dedicated `ReportJob` model that decouples state tracking from the job class itself, supporting the broader multi-type report catalogue.

**Differences from Audit pattern**:
- E24 uses one generic `ReportJob` model for all 10 report types (not one model per type).
- E24 dispatches per-type job handler classes (e.g., `GenerateWalletReportJob`, `GenerateTransactionReportJob`) that all operate on the same `ReportJob` record.
- Results are stored as JSONB in `report_jobs.result_data` rather than flattened columns.
- Job execution timeout is stored on the job record and enforced per-type (sourced from E28 config).

**Alternatives considered**: Synchronous generation (rejected: blocks HTTP response and risks timeout for large datasets); event sourcing into a separate read model (rejected: over-engineering for a reporting module that reads from live domain tables).

---

## 2. Large Dataset Export Streaming

**Decision**: Use Laravel `LazyCollection::cursor()` for DB iteration and write CSV via PHP's native `fputcsv` into a temp file, then move to Storage. For Excel, use a streaming library (e.g., OpenSpout / PhpSpreadsheet streaming writer). Export is a separate queue job (`GenerateReportExportJob`) dispatched after the report job completes.

**Rationale**: `cursor()` streams rows one-at-a-time from PostgreSQL (server-side cursor), avoiding loading the full result set into memory. Writing to a temp file and then moving to Storage is more reliable than streaming directly to S3 (avoids partial uploads on failure).

**Memory threshold**: Export jobs above a configurable row threshold (default: 10,000 rows) MUST use the cursor/streaming path. Below this threshold, `collect()` is acceptable for simplicity.

**Alternatives considered**: Direct HTTP streaming response (rejected: incompatible with async job model; download URL approach is cleaner); chunked batch inserts into a temp table (rejected: unnecessary for read-only exports).

---

## 3. Temporary Download URLs

**Decision**: Use `Storage::temporaryUrl($path, $expiry)` for S3-compatible storage (generates a pre-signed URL). For local development, implement a signed URL route (`GET /v1/reports/exports/{exportId}/download`) that validates an HMAC-signed token stored on the `ReportExport` record.

**Rationale**: `temporaryUrl()` is the standard Laravel approach for S3; the signed URL fallback for local development avoids requiring LocalStack or MinIO in the development environment.

**Token expiry default**: 1 hour for the initial download link. The file itself is retained for a configurable retention period after token expiry (default: 7 days, sourced from E28 config).

**Link regeneration**: An authorized admin can call `POST /v1/reports/exports/{exportId}/regenerate-link` to issue a new token — valid only while `purged_at IS NULL` on the export record.

---

## 4. Runtime-Configurable Report Schedules

**Decision**: DB-driven scheduling — `ReportSchedule` records store cadence and `next_run_at`. An Artisan command `reports:process-schedules` is registered in the `ReportingServiceProvider` to run every minute via Laravel's built-in scheduler (`$schedule->command('reports:process-schedules')->everyMinute()`). The command queries for active schedules where `next_run_at <= now()` and dispatches a `ReportJob` for each.

**Rationale**: Laravel's code-defined `Schedule` cannot be configured at runtime. A DB-driven poller is the standard pattern in this codebase (same approach used by E20 Reconciliation's settlement scheduler). Running every minute gives ±1 minute accuracy for scheduled reports — sufficient for daily/weekly/monthly cadences.

**Next-run computation**: After dispatching, the command increments `next_run_at` by the cadence period (1 day / 7 days / calendar month from the schedule's configured start time).

**Alternatives considered**: Laravel Horizon delayed jobs with a far-future `delay()` (rejected: fragile across restarts; can't be modified without cancelling and re-dispatching); cron expressions stored in DB (rejected: over-flexible for the three cadences the spec defines).

---

## 5. Per-User Concurrent Job Limit

**Decision**: Enforce in `ReportJobApplicationService::submit()` before dispatching — count `ReportJob` records for the requesting user in `requested` or `processing` status. If count >= configured limit (default: 3, sourced from E28 config), throw `ConcurrentJobLimitExceededException` → HTTP 429 response.

**Rationale**: DB-level count is simple, accurate, and consistent with the codebase's preference for DB-backed invariants over Redis locks for non-time-critical enforcement. The check does not need to be atomic (a small window exists where two concurrent submissions could both pass the check before either is committed) — acceptable because 3 is a soft guard, not a hard safety invariant.

**Wrapping in DB transaction**: The count-check and job record insert are wrapped in a serializable-level transaction to close the race window for production deployments.

**Alternatives considered**: Redis atomic counter per user (rejected: adds Redis coupling for a soft limit; not consistent with how other soft limits are enforced in the codebase).

---

## 6. Ops Alerting for Report Failure Rate

**Decision**: Redis sliding-window counter per report type. On each `ReportJob` failure, increment `reports:failures:{report_type}:{minute_bucket}` with a TTL equal to the alert window (default: 5 minutes). If total count across buckets in the window exceeds the configured threshold (default: 5 failures), dispatch `ReportFailureThresholdExceededEvent` via the outbox. This event is consumed by E17 Notifications to deliver an alert to the platform ops notification channel.

**Rationale**: Redis TTL-based counters are lightweight and don't require a DB write on every failure. Dispatching via the outbox ensures the alert is not lost if E17 is temporarily unavailable. The event carries `report_type`, `failure_count`, `window_minutes`, `threshold`, and `correlation_id`.

**Threshold reset**: After an alert is fired, the counters are cleared for that report type to avoid repeated alerts for the same incident window.

**Alternatives considered**: DB-based failure log with a periodic check (rejected: adds unnecessary write load); fixed-interval polling via a separate scheduled job (rejected: adds latency to alert detection).

---

## 7. PII Masking Strategy

**Decision**: Applied at the application service layer (not DB level). When building the JSONB result data for `user-report` and `kyc-report` job types, the job handler checks whether the requesting user's permission set includes `compliance.pii.read`. If not, sensitive fields (national ID number, biometric reference ID) are replaced with a masked placeholder (`***`). The permission set is resolved at job dispatch time and stored on the `ReportJob` record as a `pii_access` boolean to avoid re-querying permissions during async execution.

**Rationale**: Permission re-evaluation at async job execution time is unreliable (the requesting user's permissions might have changed). Storing the effective `pii_access` flag at dispatch time makes the masking decision reproducible and auditable.

**Alternatives considered**: DB-level column-level security (rejected: cross-schema queries make this impractical; also couples security policy to DB schema); post-export redaction (rejected: the JSONB result data in `report_jobs.result_data` would contain unmasked PII).

---

## 8. Database Schema Strategy

**Decision**: New PostgreSQL schema `reporting`. All five tables (`report_definitions`, `report_jobs`, `report_exports`, `report_schedules`, `schedule_runs`) reside in `reporting.*`. Cross-module reads use raw SQL queries via `DB::connection('platform')->table('{schema}.{table}')` — consistent with the Audit module's approach for cross-schema aggregate queries.

**Rationale**: Schema-per-module is the established pattern in this codebase (see `2026_08_01_000001_create_all_schemas.php`). Raw SQL aggregates avoid the need for cross-module Eloquent relationships.

**Module contract**: `ReportingModuleContract` is published for other modules to query report job status (e.g., for E19 Admin Portal integration). All cross-module data access goes through the contract, not direct table reads.
