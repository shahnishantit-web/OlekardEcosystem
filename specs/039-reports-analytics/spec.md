# Feature Specification: ADM-P1-015 — Reports & Analytics

**Feature Branch**: `039-reports-analytics`

**Created**: 2026-10-14

**Status**: Draft

**Input**: ADM-P1-015 — Reports & Analytics [US-P1-015-001 through US-P1-015-004]

## Overview

Administrators need a reliable self-service reporting surface to monitor operations, meet regulatory obligations, and share financial summaries. The reports module provides on-demand report generation, scheduled delivery, file download, and reconciliation oversight — all read-only operations with no financial mutations.

The existing admin app has five report-related page files already scaffolded (`report-list`, `report-job-detail`, `report-schedule-list`, `reconciliation-report`, `settlement-report`), but several gaps prevent the user stories from being fully satisfied:

- On-demand form lacks type diversity, does not redirect to the created job, and has a status-value mismatch (`ready` vs `complete`).
- Schedule management lacks edit and pause/resume.
- Download flow is split across two approaches (`download_url` from AdminAppClient vs. `POST /exports` from the direct client); needs a consistent, user-friendly path.
- Reconciliation report lacks date range filters and summary KPI cards.

This spec covers the completion work for all four stories.

---

## User Scenarios & Testing

### User Story 1 — Admin generates an on-demand report (Priority: P1)

An administrator selects a report type (Transaction Summary, Wallet Balances, Agent Commissions, KYC Status, or Reconciliation), picks a date range and output format, and requests generation. The system queues the job asynchronously and the admin can monitor progress until the report is ready for download.

**Why this priority**: The primary and most frequently used reporting action; unblocks all other reporting workflows.

**Independent Test**: Navigate to `/reports`, submit a Transaction Summary for a 7-day range — job appears in the list with "In Progress" status and transitions to "Complete" with a Download link.

**Acceptance Scenarios**:

1. **Given** I have `adminapp.reports.generate` permission and navigate to `/reports`, **When** I fill in report type, date range, and format then click Generate, **Then** the system creates a report job, shows it at the top of the list with status REQUESTED/PROCESSING, and navigates to or updates to show the new job.

2. **Given** a report job is in processing state, **When** I view the job detail, **Then** the page polls every 5 seconds and updates the status badge without requiring a manual refresh.

3. **Given** a report job completes, **When** status changes to COMPLETE, **Then** a Download button appears and clicking it initiates a file download in the selected format.

4. **Given** I submit a date range where end is before start, **When** I click Generate, **Then** client-side validation blocks submission with an inline error message.

5. **Given** the report generation API returns an error, **When** the form is submitted, **Then** I see an error banner with the failure message and no job is shown.

6. **Given** I lack `adminapp.reports.generate` permission, **When** I visit `/reports`, **Then** the Generate form is hidden and I only see the report history list (read-only mode).

---

### User Story 2 — Admin manages scheduled reports (Priority: P2)

An administrator can create, view, edit, pause/resume, and delete scheduled reports that run automatically on a recurring cadence (daily, weekly, monthly) and optionally deliver results by email.

**Why this priority**: Operationally important for daily/weekly monitoring routines, but less urgent than on-demand generation.

**Independent Test**: Navigate to `/reports/schedules` — create a daily "Transaction Summary" schedule named "Daily Ops" — confirm it appears in the list as Active with next-run date — deactivate it — confirm status changes.

**Acceptance Scenarios**:

1. **Given** I navigate to `/reports/schedules` and click Create Schedule, **When** I fill in name, report type, cadence (daily/weekly/monthly), time, and email preference, **Then** the schedule is saved and appears in the list with status Active and correct next-run time.

2. **Given** a cadence of "weekly" is selected, **When** I submit the form, **Then** a day-of-week selector is required; for "monthly", a day-of-month selector (1–28) is required.

3. **Given** an Active schedule, **When** I click Deactivate, **Then** the schedule status changes to Inactive and next-run date shows "—"; a Re-activate button appears.

4. **Given** an Inactive schedule, **When** I click Re-activate, **Then** the schedule becomes Active with a recalculated next-run date.

5. **Given** any schedule, **When** I click Deactivate, **Then** the schedule status changes to Inactive (soft deactivation via `DELETE /v1/reports/schedules/{id}`). Note: the E24 API does not provide a physical-delete endpoint; schedules can be deactivated but not permanently deleted from this UI.

6. **Given** I have no `adminapp.reports.schedule` permission, **When** I view `/reports/schedules`, **Then** Create/Deactivate/Re-activate controls are hidden and the list is read-only.

---

### User Story 3 — Admin downloads a completed report (Priority: P1)

When a report job reaches COMPLETE status, the admin can download the output file directly from the job detail page. The download is logged server-side (audit trail). The report list shows a "View" link that navigates to the detail page where the download action lives.

**Why this priority**: Completing the on-demand flow; a report that cannot be downloaded has no value.

**Independent Test**: Complete a report generation job — click Download from the list — file downloads to the browser in the selected format (CSV or XLSX).

**Acceptance Scenarios**:

1. **Given** a report job has status COMPLETE and has a download URL, **When** I click Download, **Then** the browser initiates a file download in the correct format (CSV or XLSX) matching what was requested.

2. **Given** a report job is PROCESSING or FAILED, **When** I view the job detail, **Then** the Download button is disabled (PROCESSING) or absent (FAILED).

3. **Given** a report is in FAILED status, **When** I view the detail, **Then** I see the failure reason and a "Retry" option that re-queues the same report type and date range.

4. **Given** the download URL has expired (>24 hours), **When** I click Download, **Then** the system requests a fresh download URL and then initiates the download — no broken link is shown.

---

### User Story 4 — Admin views the reconciliation report (Priority: P2)

An administrator can view a reconciliation summary for a selected date range: total transaction count, matched percentage, unmatched count, and total exception amount. From the summary, they can drill into the reconciliation batch detail.

**Why this priority**: Operational visibility into settlement accuracy; valuable but depends on reconciliation batch data from E20.

**Independent Test**: Navigate to `/reports/reconciliation` — select last 7 days — see KPI summary cards and batch table below — click a batch "Detail" link.

**Acceptance Scenarios**:

1. **Given** I navigate to `/reports/reconciliation` and submit a date range, **When** the data loads, **Then** I see four summary KPI cards: Total Transactions, Matched %, Unmatched Count, Exception Amount (BWP).

2. **Given** reconciliation batches exist for the selected period, **When** the table loads, **Then** I see batches with columns: Batch ID, Period, Matched, Unmatched, Discrepancy (BWP), Status, and a "Detail" link.

3. **Given** I click a batch "Detail" link, **When** navigated, **Then** I land on `/reconciliation/batches/{id}` (existing reconciliation detail route).

4. **Given** no batches exist for the selected date range, **When** the query returns empty, **Then** I see an empty state message: "No reconciliation batches found for the selected period."

5. **Given** the date range end is before start, **When** I click Search, **Then** client-side validation blocks submission with an inline error.

---

### Edge Cases

- What happens when a report job is still processing after 30 minutes? Polling should eventually stop (timeout) and show a "Taking longer than expected — check back later" message instead of spinning indefinitely.
- How does the system handle a report type that has no data for the selected range? The job should complete successfully with 0 rows and the download produces an empty file with headers.
- What if the admin navigates away during polling? Polling should stop when the component unmounts; no memory leaks or orphan intervals.
- What if `cadence_time` is entered in an unsupported format (e.g., "6am")? Client-side validation enforces HH:MM format.
- What if two admins create the same schedule simultaneously? The backend handles deduplication; the UI does not need special handling beyond showing the second admin's result.

---

## Requirements

### Functional Requirements

- **FR-001**: System MUST allow administrators with `adminapp.reports.generate` permission to create on-demand report jobs by specifying report type, date range (start and end), and output format.
- **FR-002**: System MUST queue report generation asynchronously and return a job ID immediately; the admin MUST NOT wait synchronously for completion.
- **FR-003**: Report job status MUST update in the UI without manual page refresh. Polling occurs on the job detail page (every 5 seconds while in REQUESTED or PROCESSING state; stop polling on COMPLETE, FAILED, after 36 iterations, or on component unmount). The generate form does not poll — it redirects to the detail page on successful submission.
- **FR-004**: System MUST provide a direct file download when a report job is COMPLETE; download is logged in the server-side audit trail.
- **FR-005**: System MUST allow administrators with `adminapp.reports.schedule` permission to create, pause, resume, and delete recurring report schedules.
- **FR-006**: Scheduled reports MUST support cadences: daily, weekly (with day-of-week), and monthly (with day-of-month 1–28).
- **FR-007**: Schedule creation MUST validate that name is non-empty and cadence-specific day fields are present and in range.
- **FR-008**: System MUST display reconciliation report summary KPIs (Total Transactions, Matched %, Unmatched Count, Exception Amount) for an admin-selected date range.
- **FR-009**: Reconciliation summary MUST link each batch row to the existing batch detail route (`/reconciliation/batches/{id}`).
- **FR-010**: All report-related pages MUST display appropriate loading, empty, and error states.
- **FR-011**: Date range validation MUST enforce that period end is not before period start; client-side check before any API call.
- **FR-012**: Polling MUST stop after a configurable maximum of 36 iterations (~3 minutes) and display a "still processing" message; the admin can manually refresh.
- **FR-013**: Download URL refresh MUST be attempted automatically when a stored download URL returns a non-2xx response (expired URL scenario).

### Key Entities

- **ReportJob**: Represents an asynchronous report generation request. Key attributes: id, report_type, status (requested/processing/complete/failed), row_count, filters, output format, download_url, failure_reason, created_at, completed_at.
- **ReportSchedule**: A recurring report configuration. Key attributes: id, name, report_type, cadence (daily/weekly/monthly), cadence_time, cadence_day_of_week, cadence_day_of_month, status (active/paused), next_run_at, email_notification_enabled.
- **ReconciliationBatch**: Existing E20 entity — batch_id, period_start, period_end, matched_count, unmatched_count, discrepancy_amount_minor_units, status.
- **ReconciliationSummary**: Derived aggregate for the date-range view — total_transactions, matched_pct, unmatched_count, exception_amount_minor_units (computed client-side from batch list or from a summary API endpoint).

---

## Success Criteria

### Measurable Outcomes

- **SC-001**: An admin can generate an on-demand report and see it in COMPLETE status within 60 seconds for a 7-day transaction summary (assuming backend processing time is within SLA).
- **SC-002**: Report download initiates within 2 seconds of clicking the Download button for completed jobs with a valid download URL.
- **SC-003**: Schedule creation completes in under 3 clicks (open panel → fill form → save) and the new schedule is visible in the list immediately.
- **SC-004**: Reconciliation summary KPIs load within 3 seconds for a 30-day date range.
- **SC-005**: Zero JavaScript errors occur during polling when the browser tab is backgrounded or the component is unmounted before polling completes.
- **SC-006**: All interactive controls (Generate, Download, Pause, Resume, Delete) are keyboard-accessible and have descriptive ARIA labels.

---

## Assumptions

- The E24 backend reporting APIs (`GET /v1/reports/jobs`, `POST /v1/reports/jobs`, `GET /v1/reports/jobs/{id}`) and the AdminAppClient wrappers (`createReport`, `getReport`) are functional and return the expected response shapes.
- Permissions `adminapp.reports.generate` and `adminapp.reports.schedule` are defined in the E04 RBAC system and available via `hasPermission()` from `useAuth()`.
- The `download_url` field returned by `getReport` when status is `ready`/`complete` is a pre-signed URL valid for at least 24 hours.
- The AdminAppClient's `createReport` endpoint returns a `report_id` (separate from `id`); polling uses `getReport(report_id)` and receives `status` as `pending`/`processing`/`ready`/`failed` — the UI normalises these to `requested`/`processing`/`complete`/`failed` for display.
- `GET /v1/reports/schedules`, `POST /v1/reports/schedules`, `DELETE /v1/reports/schedules/{id}` (soft deactivate — sets `status: 'inactive'`, not physical delete), and `PUT /v1/reports/schedules/{id}` (full update including re-activation — sets `status: 'active'`) are available per the E24 contract. There is no physical-delete endpoint.
- The reconciliation batch list at `GET /v1/admin-app/reconciliation` is already functional (used by existing `ReconciliationReportPage`); the reconciliation summary KPIs will be derived client-side from the batch list totals (no new API endpoint required).
- The report status value mismatch between `AdminAppClient` (`ready`) and the direct client's list page (`complete`) will be resolved by normalising in the UI layer; the backend may return either value depending on path.
- Desktop-first layout; the reports pages are not optimised for mobile.
