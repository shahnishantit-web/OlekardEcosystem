# Data Model — E24 Reporting & Analytics

PostgreSQL schema: `reporting`

---

## Table: `reporting.report_definitions`

Seeded catalogue of available report types. Not user-created; updated only via migrations.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` (v7) | PK |
| `type` | `varchar(64)` | Unique slug: `user-report`, `wallet-report`, `transaction-report`, `agent-report`, `commission-report`, `kyc-report`, `reconciliation-report`, `settlement-report`, `risk-report`, `financial-summary` |
| `name` | `varchar(128)` | Human-readable name |
| `description` | `text` | Displayed in catalogue |
| `required_permission` | `varchar(128)` | Permission slug required to request this report type |
| `pii_sensitive` | `boolean` | If true, `pii_access` flag is stored on jobs of this type |
| `filter_schema` | `jsonb` | JSON Schema describing accepted filter parameters (used for validation) |
| `default_max_rows` | `integer` | Default row limit for this type (overrides global default if set) |
| `is_active` | `boolean` | Soft-disable a report type without deleting |
| `created_at` | `timestamptz` | |
| `updated_at` | `timestamptz` | |

**Indexes**: unique on `type`.

---

## Table: `reporting.report_jobs`

One record per report generation request. Append-only status transitions.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` (v7) | PK |
| `definition_id` | `uuid` | FK → `reporting.report_definitions.id` |
| `report_type` | `varchar(64)` | Denormalized from definition for query convenience |
| `requested_by_user_id` | `uuid` | FK → `identity.users.id`; owner of the job |
| `correlation_id` | `uuid` | Propagated from the HTTP request |
| `filters` | `jsonb` | Validated filter parameters as submitted |
| `as_of_at` | `timestamptz` | Timestamp when the live query began (set by job handler at start) |
| `status` | `varchar(32)` | Enum: `requested` → `processing` → `complete` \| `failed` |
| `pii_access` | `boolean` | Resolved at submission time; `true` if requesting user had `compliance.pii.read` |
| `row_count` | `integer` | Populated on completion |
| `result_data` | `jsonb` | Pageable structured result (NULL while in-progress; populated on completion) |
| `failure_reason` | `varchar(512)` | Populated on failure |
| `created_at` | `timestamptz` | |
| `started_at` | `timestamptz` | Set when job begins processing |
| `completed_at` | `timestamptz` | Set on `complete` or `failed` |

**Indexes**:
- `(requested_by_user_id, status)` — for per-user concurrent job count check
- `(report_type, status, created_at)` — for ops alerting window query
- `(status, created_at)` — for schedule processing and admin list views

**Constraints**:
- `status` must follow the allowed transition sequence (enforced in application service)
- `result_data` is NULL when `status != 'complete'`

---

## Table: `reporting.report_exports`

One record per export file generated from a completed job. Files are retained post-link-expiry.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` (v7) | PK |
| `job_id` | `uuid` | FK → `reporting.report_jobs.id` |
| `format` | `varchar(16)` | `csv` or `excel` |
| `storage_path` | `varchar(512)` | Opaque path in configured storage disk |
| `file_size_bytes` | `bigint` | Set on completion |
| `row_count` | `integer` | Matches the parent job's `row_count` |
| `download_token` | `varchar(256)` | HMAC-signed token for local-driver signed URL; NULL for S3 (pre-signed URL generated on demand) |
| `token_expires_at` | `timestamptz` | When the current download link expires |
| `file_retention_expires_at` | `timestamptz` | When the file may be purged (token_expires_at + configurable retention window) |
| `purged_at` | `timestamptz` | NULL until the file is deleted from storage |
| `created_by_user_id` | `uuid` | User who requested the export |
| `created_at` | `timestamptz` | |

**Indexes**:
- `(job_id)` — for listing exports per job
- `(file_retention_expires_at)` where `purged_at IS NULL` — for scheduled purge command
- `(download_token)` — for signed URL validation (local driver only)

---

## Table: `reporting.report_schedules`

Runtime-configurable recurring report configurations.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` (v7) | PK |
| `definition_id` | `uuid` | FK → `reporting.report_definitions.id` |
| `report_type` | `varchar(64)` | Denormalized |
| `owner_user_id` | `uuid` | FK → `identity.users.id`; user who created the schedule |
| `name` | `varchar(128)` | Human-readable label for the schedule |
| `filters` | `jsonb` | Default filter parameters applied to each triggered job |
| `cadence` | `varchar(16)` | `daily`, `weekly`, `monthly` |
| `cadence_time` | `time` | Time of day for the run (UTC) |
| `cadence_day_of_week` | `smallint` | NULL for daily/monthly; 0 (Mon) – 6 (Sun) for weekly |
| `cadence_day_of_month` | `smallint` | NULL for daily/weekly; 1–28 for monthly |
| `email_notification_enabled` | `boolean` | Whether email notifications are sent in addition to in-app |
| `recipients` | `jsonb` | Array of `{user_id, email}` objects |
| `status` | `varchar(16)` | `active` or `inactive` |
| `next_run_at` | `timestamptz` | When the next job will be triggered |
| `last_run_at` | `timestamptz` | When the last job was triggered (NULL if never run) |
| `created_at` | `timestamptz` | |
| `updated_at` | `timestamptz` | |

**Indexes**:
- `(status, next_run_at)` — for schedule processing command
- `(owner_user_id)` — for listing user's own schedules

**Constraint**: `cadence_day_of_month` capped at 28 to avoid month-length edge cases (Feb 29, etc.).

---

## Table: `reporting.schedule_runs`

Immutable record of each schedule execution. Append-only.

| Column | Type | Notes |
|---|---|---|
| `id` | `uuid` (v7) | PK |
| `schedule_id` | `uuid` | FK → `reporting.report_schedules.id` |
| `triggered_at` | `timestamptz` | When the run was triggered by the scheduler command |
| `job_id` | `uuid` | FK → `reporting.report_jobs.id`; the job created for this run |
| `outcome` | `varchar(16)` | `dispatched`, `failed` (populated as job completes, or immediately on dispatch failure) |
| `failure_reason` | `varchar(512)` | NULL unless outcome is `failed` |
| `created_at` | `timestamptz` | |

**Index**: `(schedule_id, triggered_at DESC)` — for run history queries.

---

## Configuration Keys (sourced from E28 Platform Configuration)

| Key | Default | Description |
|---|---|---|
| `reports.max_rows_default` | `100000` | Global maximum row limit per report job |
| `reports.execution_timeout_seconds` | `300` | Job execution timeout in seconds |
| `reports.concurrent_jobs_per_user` | `3` | Per-user concurrent job limit |
| `reports.export_link_ttl_seconds` | `3600` | Download link validity period (1 hour) |
| `reports.export_file_retention_days` | `7` | Days to retain export files after link expiry |
| `reports.alert_failure_threshold` | `5` | Failure count to trigger ops alert |
| `reports.alert_window_minutes` | `5` | Sliding window for failure rate monitoring |

---

## Permissions (must be seeded in E04 Roles & Permissions)

| Permission slug | Description |
|---|---|
| `admin.read` | Required for general reports (user, agent reports) |
| `finance.reports` | Required for reconciliation, settlement, financial summary |
| `compliance.reports` | Required for KYC and risk reports |
| `reports.schedule` | Required to create/update/deactivate report schedules |
| `compliance.pii.read` | Required to receive unmasked PII in user and KYC reports |

---

## Domain Events

| Event class | Trigger | Payload |
|---|---|---|
| `ReportJobRequestedEvent` | Job record created | `job_id`, `report_type`, `user_id`, `correlation_id` |
| `ReportJobCompletedEvent` | Job transitions to `complete` | `job_id`, `report_type`, `row_count`, `as_of_at`, `correlation_id` |
| `ReportJobFailedEvent` | Job transitions to `failed` | `job_id`, `report_type`, `failure_reason`, `correlation_id` |
| `ReportExportGeneratedEvent` | Export file ready | `export_id`, `job_id`, `format`, `user_id`, `correlation_id` |
| `ReportScheduleTriggeredEvent` | Scheduler command dispatches a job | `schedule_id`, `job_id`, `triggered_at`, `correlation_id` |
| `ReportFailureThresholdExceededEvent` | Redis failure count exceeds threshold | `report_type`, `failure_count`, `window_minutes`, `threshold`, `correlation_id` |
