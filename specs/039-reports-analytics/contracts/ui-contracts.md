# UI Contracts: ADM-P1-015 — Reports & Analytics

**Date**: 2026-10-14

Backend API contracts are in `specs/024-reporting-analytics/contracts/reporting.yaml`.

This document covers the frontend component interface contracts — the props, events, and state each component promises to the rest of the application.

---

## ReportGenerateForm

Inline component within `ReportListPage`. Manages report generation and inline status tracking.

**Props**: `{ client: AuthenticatedApiClient }`

**State outputs** (via callbacks/internal display):
- On submit success: Displays new `AdminAppReportJob` in a status widget; starts polling.
- On polling complete (`status === 'complete'`): Shows Download link; stops polling.
- On poll timeout (36 iterations): Shows "Taking longer than expected" message.
- On API error: Shows `role="alert"` banner with error message.

**Validation contracts**:
- `date_range_end >= date_range_start` — checked before submit
- `date_range_start` and `date_range_end` must be non-empty — checked before submit

**Permissions**: Renders form only when `hasPermission('adminapp.reports.generate')`.

---

## ReportListPage

Route: `/reports`

**Contract**:
- Fetches `GET /v1/reports/jobs` on mount; re-fetches when status or type filter changes.
- Renders `ReportGenerateForm` above the job table (only when permission allows).
- Each COMPLETE row shows a "Download" button; all rows show "View" → navigates to `/reports/{id}`.
- Loading/error/empty states handled.

---

## ReportJobDetailPage

Route: `/reports/[id]`

**Contract**:
- Fetches `GET /v1/reports/jobs/{id}` on mount.
- Polls every 5s while status is `requested` or `processing` (max 36 iterations).
- When status is `complete`:
  - Export buttons (CSV / XLSX) enabled.
  - Clicking Export: calls `POST /v1/reports/jobs/{id}/exports`, polls `GET /v1/reports/exports/{exportId}`, opens `download_url` in new tab when ready.
- When status is `failed`: shows `failure_reason` and a "Retry" button that re-submits the same report type + filters.
- No download when `pending` or `processing`.

---

## ReportScheduleListPage

Route: `/reports/schedules`

**Contract**:
- Fetches `GET /v1/reports/schedules` on mount.
- Shows Create button (opens `Sheet` panel) — only when `hasPermission('adminapp.reports.schedule')`.
- Active schedules: show Deactivate button → `DELETE /v1/reports/schedules/{id}`.
- Inactive schedules: show Re-activate button → `PUT /v1/reports/schedules/{id}` with `status: 'active'`.
- Create form: validates name, cadence day fields; submits `POST /v1/reports/schedules`; closes Sheet on success; re-fetches.
- Schedule + create/deactivate/reactivate controls hidden when `!hasPermission('adminapp.reports.schedule')`.

---

## ReconciliationReportPage

Route: `/reports/reconciliation`

**Contract**:
- Initial state: shows date range filter form (start/end inputs + Search button); batch table hidden.
- On Search submit (with valid date range): fetches `GET /v1/admin-app/reconciliation?date_from={start}&date_to={end}` (all pages).
- When data loads: renders four KPI cards above the batch table:
  - Total Transactions
  - Matched % (rounded to 1 decimal)
  - Unmatched Count
  - Exception Amount (BWP, 2 decimal places)
- Batch table: Batch ID (truncated + mono), Period, Matched, Unmatched, Discrepancy (BWP), Status badge, Detail link.
- Detail link: `<a href="/reconciliation/batches/{batch_id}">` — navigates to existing reconciliation module.
- Empty state: "No reconciliation batches found for the selected period."
- Validates end ≥ start before fetching.
