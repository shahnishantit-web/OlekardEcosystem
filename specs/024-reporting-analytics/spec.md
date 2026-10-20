# Feature Specification: E24 — Reporting & Analytics

**Feature Branch**: `024-reporting-analytics`

**Created**: 2026-10-01

**Status**: Draft

**Input**: User description: "E24 reporting-analytics"

## Overview

The Reporting & Analytics module provides authorized operations and administration users with a unified platform for generating, filtering, exporting, and scheduling reports across every business domain of the OleKard wallet ecosystem. Reports cover wallets, transactions, reconciliation, settlements, agents, commissions, KYC/compliance, risk, and financial summaries.

Reports are generated asynchronously as named jobs, tracked through a lifecycle (requested → processing → complete/failed), and made available for download in structured formats (CSV, Excel). Authorized users can define reusable report templates, trigger on-demand runs, and configure schedules for automated delivery. Every report request and export is audited. The module draws from existing domain data — it does not own authoritative financial records; it reads and aggregates them for insight.

## Actors

- **Operations Admin** — Requests and downloads reports; manages report schedules; primary day-to-day user.
- **Finance Admin** — Requests financial summaries, settlement and reconciliation reports; requires elevated permissions.
- **Compliance Officer** — Requests KYC, risk, and AML-related reports; access gated by compliance permissions.
- **System (Scheduler)** — Triggers pre-configured scheduled reports automatically on their defined cadence.
- **Super Admin** — Full access to all reports and report management; manages global report definitions.

## User Scenarios & Testing

### User Story 1 — Transaction & Wallet Reports (Priority: P1)

An operations or finance admin needs an accurate, filterable view of wallet balances and transaction activity across the platform. They open the reporting interface, select the wallet or transaction report type, apply filters (date range, wallet status, transaction type, amount range, currency), and submit the request. The system generates the report asynchronously, notifies when ready, and makes it available for immediate download or in-browser review.

**Why this priority**: Wallet and transaction data are the core financial record of the platform. These reports are required for daily operations, dispute resolution, and financial oversight. US-REPORT-002 (wallet) and US-REPORT-003 (transaction) carry P0 priority in the backlog.

**Independent Test**: Can be fully tested by submitting a wallet report request with a date filter, waiting for the job to complete, downloading the report, and verifying that wallet records appear with correct balance snapshots, status, and currency values.

**Acceptance Scenarios**:

1. **Given** an authorized user submits a wallet report request with a date-range filter, **When** the report job completes, **Then** the report contains one row per wallet with wallet ID, owner reference, currency, current balance, status, and creation date.
2. **Given** an authorized user submits a transaction report with filters (date range, transaction type, status), **When** the report job completes, **Then** the report contains one row per matching transaction with transaction ID, type, amount, currency, status, sender, receiver, timestamp, and reference.
3. **Given** a report job is submitted, **When** the system begins processing, **Then** the job status transitions from `requested` → `processing` → `complete` (or `failed` with a reason).
4. **Given** a report job fails (e.g., data unavailable, timeout), **When** the failure occurs, **Then** the job status is set to `failed`, a reason is recorded, the user is notified, and no partial file is offered for download.
5. **Given** an actor without `admin.read` permission submits a report request, **When** the request is received, **Then** the system returns 403 Forbidden and no job is created.
6. **Given** a completed report job, **When** an authorized user requests the download URL, **Then** the URL is valid, time-limited (expires after a configurable period), and authenticated.

---

### User Story 2 — Reconciliation & Settlement Reports (Priority: P1)

A finance admin needs to reconcile platform transaction records against provider settlement files and review settlement batch summaries. They request a reconciliation report for a given date range or settlement cycle, review matched and unmatched items, and download the settlement report for accounting handoff.

**Why this priority**: Reconciliation and settlement reports underpin financial integrity and regulatory compliance. US-REPORT-007 (reconciliation) carries P0 and US-REPORT-008 (settlement) carries P1 in the backlog. These reports directly validate the outputs of E20.

**Independent Test**: Can be fully tested by triggering a reconciliation report over a known batch, confirming matched items equal the sum of the settlement records, and verifying that any unmatched items appear with a reconciliation status of `unmatched`.

**Acceptance Scenarios**:

1. **Given** a finance admin submits a reconciliation report request for a date range, **When** the job completes, **Then** the report lists each transaction with its reconciliation status (`matched`, `unmatched`, `exception`), provider reference, and variance amount if applicable.
2. **Given** a settlement report request is submitted, **When** the job completes, **Then** the report contains each settlement batch with batch ID, settlement date, total amount, currency, participating agents/providers, and status.
3. **Given** a reconciliation report with unmatched items, **When** the report is downloaded, **Then** unmatched items are clearly identified with the transaction ID, expected amount, reported amount, and the source of the discrepancy.
4. **Given** an actor without `finance.reports` permission attempts to access reconciliation or settlement reports, **When** the request is received, **Then** the system returns 403 Forbidden.

---

### User Story 3 — Agent, Commission & KYC Reports (Priority: P1)

Operations and compliance administrators need visibility into agent performance, commission accruals, and KYC verification outcomes. They request targeted reports by agent ID, date range, commission status, or KYC tier, and review the results to monitor onboarding health, agent productivity, and regulatory completeness.

**Why this priority**: These reports drive agent oversight, payroll validation, and regulatory KYC attestations — core operational needs. Covers US-REPORT-001 (user), US-REPORT-004 (agent), US-REPORT-005 (commission), US-REPORT-006 (KYC) at P1.

**Independent Test**: Can be fully tested by requesting an agent report filtered to a specific agent ID and verifying the returned report contains that agent's transaction count, float balance, commission earned, and status for the period.

**Acceptance Scenarios**:

1. **Given** an authorized user requests a user report filtered by registration date range and KYC status, **When** the job completes, **Then** the report lists each user with user ID, name (masked per data policy), registration date, KYC tier, wallet count, and account status.
2. **Given** an operations admin requests an agent report, **When** the job completes, **Then** the report lists each agent with agent ID, name, region, active wallet count, total transaction volume for the period, float balance, and status.
3. **Given** a finance admin requests a commission report filtered by date range and commission status (`pending`, `paid`, `reversed`), **When** the job completes, **Then** the report lists each commission entry with agent ID, transaction reference, commission amount, currency, status, and settlement date.
4. **Given** a compliance officer requests a KYC report filtered by tier and date range, **When** the job completes, **Then** the report lists each KYC verification outcome with user ID, document type, tier achieved, verification date, result, and reviewer reference.
5. **Given** a compliance officer requests a report that would expose full PII, **When** the job is processed, **Then** sensitive fields (national ID, biometric reference) are masked or omitted unless the requesting user holds `compliance.pii.read` permission.

---

### User Story 4 — Risk & Financial Summary Reports (Priority: P1)

A compliance or finance officer needs a cross-cutting view of risk exposure and financial platform health. They request a risk report (AML flags, suspicious activity counts, velocity breach summaries) and a financial summary (total inflows, outflows, commissions, fees, net position) for a given period, and use these to inform regulatory reporting and management dashboards.

**Why this priority**: Risk and financial summary reports aggregate data from multiple domains (E18 Fraud/AML, E14 Commission, E21 Fees, E07 Ledger) into actionable management intelligence. US-REPORT-009 and US-REPORT-010 at P1.

**Independent Test**: Can be fully tested by requesting a financial summary for a known period and verifying that the total inflow equals the sum of all funding transactions and the net position equals inflows minus outflows minus fees for that period.

**Acceptance Scenarios**:

1. **Given** a compliance officer requests a risk report for a date range, **When** the job completes, **Then** the report includes total AML flag count by category, transaction velocity breach count, users with active restrictions, and top-N flagged users (anonymized unless `compliance.pii.read`).
2. **Given** a finance admin requests a financial summary for a period, **When** the job completes, **Then** the report shows total funding inflows, peer-to-peer transfer volume, withdrawal outflows, bill payment volume, total commissions, total fees collected, and net platform position — all broken down by currency.
3. **Given** a financial summary report, **When** the data is computed, **Then** total inflows minus total outflows minus net fees equals the reported net platform position (within rounding tolerance per currency).
4. **Given** an actor without `reports.risk` or `reports.financial` permission requests these reports, **When** the request is received, **Then** the system returns 403 Forbidden.

---

### User Story 5 — Report Export (Priority: P1)

An operations or finance user needs to download completed reports in a structured file format for use in spreadsheet tools or data pipelines. They select a completed report job and choose CSV or Excel format. The system generates the export file asynchronously, makes it available via a secure download link, and records the export action in the audit trail.

**Why this priority**: Exports are how report data flows into external systems (accounting, compliance, management). CSV export (US-REPORT-011) is P1; Excel export (US-REPORT-012) is P2 — CSV is implemented first.

**Independent Test**: Can be fully tested by completing a wallet report job, requesting a CSV export, downloading the file, opening it, and confirming it contains the expected columns, correct row count, proper encoding, and no truncation.

**Acceptance Scenarios**:

1. **Given** a completed report job, **When** an authorized user requests a CSV export, **Then** the system generates a well-formed CSV file with a header row, one data row per record, correct field delimiters, and UTF-8 encoding.
2. **Given** a completed report job, **When** an authorized user requests an Excel export, **Then** the system generates a valid `.xlsx` file with a named worksheet, header row with bold formatting, and one data row per record.
3. **Given** an export is requested for a report that is still `processing`, **When** the request is received, **Then** the system returns a status indicating the report is not yet available for export.
4. **Given** an export is requested for a report with more than 100,000 rows, **When** the export is generated, **Then** the system produces the file in streaming/chunked fashion without holding the full dataset in memory, and the download completes successfully.
5. **Given** a download link for an export, **When** the link's configured expiry time has passed, **Then** the link returns 410 Gone and the file is no longer accessible via that URL.
6. **Given** an export is generated, **When** it becomes available, **Then** an audit event is recorded with the report ID, export format, requesting user ID, and timestamp.

---

### User Story 6 — Scheduled Reports (Priority: P2)

An operations admin needs certain reports to be generated and delivered automatically on a recurring schedule (daily, weekly, monthly) without manual intervention. They configure a report schedule by selecting a report type, applying default filters, choosing a delivery cadence, and specifying recipients. The system runs the report at the scheduled time, generates the export, and makes it available (or delivers it via notification channel).

**Why this priority**: Scheduled reports reduce manual effort for recurring operational and compliance needs. US-REPORT-013 is P2 — implemented after the on-demand report capability is stable.

**Independent Test**: Can be fully tested by creating a daily schedule for a transaction report, advancing the system clock past the next scheduled time, and verifying a new report job was created automatically with the correct filters and the schedule's next-run time was updated.

**Acceptance Scenarios**:

1. **Given** an authorized user creates a report schedule with type, filters, cadence (daily/weekly/monthly), and optional recipient list, **When** the schedule is saved, **Then** a schedule record is persisted with status `active`, next-run time computed from the cadence, and the configuration audited.
2. **Given** a report schedule with status `active`, **When** the system clock reaches the next-run time, **Then** the system automatically creates a report job with the schedule's filter configuration and updates the schedule's next-run time.
3. **Given** a scheduled report job completes successfully, **When** the report is ready, **Then** all configured recipients receive an in-app notification that the report is available; recipients with email notification opted in for that schedule also receive an email notification.
4. **Given** a scheduled report job fails, **When** the failure is recorded, **Then** the schedule remains active, the failure reason is logged against the schedule run history, and an alert is sent to the schedule owner.
5. **Given** an authorized user deactivates a report schedule, **When** the deactivation is confirmed, **Then** the schedule status changes to `inactive`, no further jobs are triggered, and the change is audited.
6. **Given** an unauthorized user attempts to create or modify a report schedule, **When** the request is received, **Then** the system returns 403 Forbidden.

---

### Edge Cases

- What happens when a report is requested over a date range that spans millions of rows? The system must enforce a configurable maximum row limit and reject requests that exceed it with a descriptive error before job creation.
- What happens when a user submits a 4th report job while 3 are already active? The system rejects the submission with HTTP 429 and a message stating the current active count and the per-user limit (default: 3, configurable).
- How does the system handle a report job that hangs (no response from data layer within timeout)? Jobs must have a configurable execution timeout; timed-out jobs transition to `failed` with reason `execution_timeout`.
- What happens if a user is deleted or anonymized between report request and job completion? Deleted/anonymized user data must appear in the report with pseudonymized identifiers consistent with the data retention/GDPR policy.
- What if two users simultaneously request the same report with identical parameters? Each request generates an independent job; deduplication within a configurable window is a future optimization, not a requirement.
- What happens to export download links after a report is archived or purged? The link returns 410 Gone; the audit trail entry for the original export is preserved. If the file is still within its post-expiry retention window, an authorized admin may regenerate a new time-limited download link without re-running the report job.
- How does the system handle currency conversion for multi-currency financial summaries? Each row retains its native currency; summaries group by currency; cross-currency totals are not computed (to avoid FX rate dependency).

## Requirements

### Functional Requirements

- **FR-001**: The system MUST support on-demand report generation for all 10 report data types (US-REPORT-001 through US-REPORT-010). Export formats (US-REPORT-011: CSV, US-REPORT-012: Excel) and recurring scheduling (US-REPORT-013) are capabilities implemented as separate subsystems, not distinct `ReportType` enum values.
- **FR-002**: Report generation MUST be asynchronous; jobs MUST progress through states: `requested` → `processing` → `complete` | `failed`.
- **FR-003**: Each report job MUST record: job ID, report type, requesting user ID, filter parameters, status, created-at, started-at, as-of timestamp (the moment the live query begins), completed-at, and failure reason (if applicable).
- **FR-004**: Report jobs MUST be accessible only to the user who requested them or to users with super-admin report permissions.
- **FR-005**: The system MUST support filter parameters per report type: minimum date range, maximum date range, entity ID filters (user, agent, wallet), status filters, and currency filters where applicable.
- **FR-006**: The system MUST enforce a configurable maximum row limit per report job; requests exceeding the limit MUST be rejected with a descriptive error before job creation.
- **FR-006b**: The system MUST enforce a per-user concurrent job limit of 3 (configurable); if a user already has 3 jobs in `requested` or `processing` state, any further submission MUST be rejected with a descriptive error indicating the active job count and the limit.
- **FR-007**: The system MUST enforce a configurable job execution timeout; jobs that exceed the timeout MUST transition to `failed` with reason `execution_timeout`.
- **FR-008**: Completed report jobs MUST support export in CSV format.
- **FR-009**: Completed report jobs MUST support export in Excel (`.xlsx`) format.
- **FR-010**: Export generation MUST stream large datasets to avoid exhausting server memory; the system MUST not hold the full dataset in memory for exports exceeding a configurable row threshold.
- **FR-011**: Export download links MUST be authenticated and time-limited; expired links MUST return a non-200 response and no file content. The underlying export file MUST be retained in storage for a configurable period after link expiry before being purged by a scheduled process; an authorized admin MUST be able to regenerate a valid download link for a retained file within that window.
- **FR-012**: The system MUST support creating, reading, updating, and deactivating report schedules.
- **FR-013**: Report schedules MUST support daily, weekly, and monthly cadences with a configurable start time.
- **FR-014**: The system MUST automatically trigger a report job at the next-run time of each active schedule and update the schedule's next-run time on completion.
- **FR-015**: When a scheduled report completes or fails, all configured recipients MUST receive an in-app notification; recipients who have opted in to email for that schedule MUST also receive an email notification. The schedule configuration MUST include a per-schedule email opt-in flag, and each recipient entry MUST carry the user ID plus an optional email address for delivery.
- **FR-016**: Every report request, job status change, export generation, and schedule modification MUST produce an audit event.
- **FR-017**: Authorization MUST be enforced server-side per report type; the required permissions are: `admin.read` for general reports, `finance.reports` for reconciliation/settlement/financial-summary, `compliance.reports` for KYC and risk reports, and `reports.schedule` for schedule management.
- **FR-018**: Sensitive PII fields in user and KYC reports MUST be masked unless the requesting user holds `compliance.pii.read` permission.
- **FR-019**: Financial summary reports MUST NOT perform cross-currency aggregation; totals MUST be grouped by currency.
- **FR-020**: The system MUST provide a report definition catalogue listing all available report types, their supported filters, and required permissions.
- **FR-021**: The system MUST raise a platform operations alert when the failure rate for any single report type exceeds a configurable threshold (count of failures within a configurable time window); this alert is separate from the per-user or per-schedule failure notification and is delivered via the platform's operational alerting channel.

### Key Entities

- **ReportDefinition**: Describes a report type — its name, supported filter schema, required permissions, and default configuration. Managed by platform administrators; not created by end users.
- **ReportJob**: A single execution of a report — stores job ID, report definition reference, requesting user, filter snapshot, status, execution timestamps, as-of timestamp (when the live query began), row count, and error details. Append-only status transitions. Two runs of the same report with the same filters may return different results if underlying data changed between runs; the as-of timestamp makes this explicit.
- **ReportExport**: Represents a generated file artifact — stores export ID, job reference, format (CSV/Excel), file size, storage reference, download token, token expiry, file retention expiry (configurable window after token expiry), purged-at timestamp, and creation timestamp. Files are retained past link expiry for a configurable period to allow link regeneration; a scheduled purge removes files after the retention window closes.
- **ReportSchedule**: A recurring job configuration — stores schedule ID, report definition reference, filter snapshot, cadence, next-run time, status (active/inactive), owner user, email-notification opt-in flag, recipients (each with user ID and optional email address), and run history.
- **ScheduleRun**: An immutable record of each scheduled execution — stores schedule ID, triggered-at, job reference, outcome, and failure reason.

## Success Criteria

### Measurable Outcomes

- **SC-001**: An authorized user can request any of the 13 report types and receive a completed report within 5 minutes for datasets up to 50,000 rows under normal platform load.
- **SC-002**: Report exports of up to 500,000 rows complete successfully without server-side memory errors.
- **SC-003**: Scheduled reports execute within 5 minutes of their configured scheduled time, achieving on-time delivery for 99% of scheduled runs.
- **SC-004**: 100% of report requests, export generations, and schedule changes produce a corresponding audit event with no gaps under load testing.
- **SC-005**: An unauthorized user is denied access to any report endpoint, with 0% false negatives in authorization enforcement across all 13 report types and schedule management endpoints.
- **SC-006**: Financial summary reports balance: total inflows minus total outflows minus net fees equals the reported net platform position within acceptable rounding tolerance, validated against the source ledger.
- **SC-007**: PII masking is applied correctly to 100% of user and KYC report records when the requesting user lacks `compliance.pii.read` permission, with no unmasked PII leaking.
- **SC-008**: Users can navigate to any report, submit a request, and download results without needing technical assistance — task completion rate ≥ 95% in usability testing.
- **SC-009**: Systemic report failures (same report type failing repeatedly) trigger a platform operations alert within the configured threshold window, with 0 missed alerts during load and chaos testing.

## Assumptions

- All report data is sourced by reading from domain data stores (wallets, transactions, ledgers, agents, KYC records, risk flags) at live query time when the job starts; the Reporting module does not own authoritative records and does not store data snapshots. The as-of timestamp on each job is the definitive record of when the query ran.
- Report job execution is handled by background workers (queue-based); the API layer is responsible only for job creation and status polling.
- File storage for completed exports uses the platform's existing secure object storage; the Reporting module stores only a reference and time-limited token.
- Currency conversion rates are not available to this module; multi-currency reports group by currency and do not compute cross-currency totals.
- Notification delivery (for schedule completion alerts) is delegated to the E17 Notifications module via domain events.
- The audit trail for report events is delegated to the E23 Audit & Compliance module via the published audit event contract.
- Data retention for report jobs and export artifacts follows the configurable retention policy managed by the E28 Configuration Management module.
- The maximum row limit and execution timeout are configurable through the centralized Admin configuration capability (E28) and have sensible defaults set during seeding.
- Mobile applications (Flutter) are out of scope for report generation and management; reporting is an Admin Portal (Next.js) and API capability only.
- Report definitions (the catalogue of available report types) are seeded during deployment; adding new report types requires a platform release, not a runtime configuration change.

## Clarifications

### Session 2026-10-01

- Q: Should report data reflect live state at job execution time or a stored point-in-time snapshot? → A: Live query at job start time; as-of timestamp stored on the job record (Option A).
- Q: How many report jobs should a single user be allowed to have running or queued concurrently? → A: 3 concurrent jobs per user (configurable); further submissions rejected with HTTP 429 (Option B).
- Q: After a report export download link expires, should the file be deleted immediately or retained for a further period? → A: Retain for a configurable period after link expiry; purge on schedule; authorized admins can regenerate the link within the retention window (Option B).
- Q: When a scheduled report completes, should recipients be notified via in-app only or email as well? → A: In-app notification always; email opt-in per schedule; recipient entries carry user ID plus optional email address (Option B).
- Q: When a report job fails, should an ops-team alert be raised in addition to the per-user notification? → A: Yes — threshold-based ops alert when failure count for a report type exceeds a configurable threshold within a configurable window; per-user notification handles individual failures (Option B).
