# Research: ADM-P1-015 — Reports & Analytics

**Date**: 2026-10-14

No NEEDS CLARIFICATION markers remained in the spec. All decisions were resolved through codebase inspection of existing report pages and the E24 backend contract (`specs/024-reporting-analytics/contracts/reporting.yaml`).

---

## Decision 1: API client strategy — AdminAppClient vs. direct client

**Decision**: Use `authenticatedClient` directly for all report API calls in `src/modules/reports/api/reports.ts`. Do not extend `AdminAppClient`.

**Rationale**: The existing `report-list.tsx` already fetches the job list from `/v1/reports/jobs` via `authenticatedClient.get`. The `report-job-detail.tsx` polls `/v1/reports/jobs/{id}` the same way. The AdminAppClient's `createReport` uses `/v1/admin-app/reports` — a different base path that goes through the admin-app proxy. This dual-path situation is confusing. The `AdminAppReportForm` component embedded in `report-list.tsx` will continue to use `AdminAppClient.createReport` for the form submission since it returns a `report_id` field; all status polling after that should use `AdminAppClient.getReport(report_id)` for consistency with the response shape (which has `report_id` not `id`).

**Alternatives considered**:
- Migrate all report calls to AdminAppClient — rejected because it would require modifying AdminAppClient and changing the job list endpoint, which could break existing functionality.
- Use only the direct `/v1/reports/jobs` path — rejected because the AdminAppClient's `createReport` already exists and is used; rewriting it would exceed the scope of this epic.

---

## Decision 2: Status normalisation

**Decision**: Map both admin-app status values and direct API status values to a single canonical display set in the UI.

**Canonical display set**:
- `requested` / `pending` → display as "REQUESTED"
- `processing` → display as "PROCESSING"  
- `complete` / `ready` → display as "COMPLETE"
- `failed` → display as "FAILED"

**Rationale**: The E24 direct API (`/v1/reports/jobs`) uses `requested | processing | complete | failed`. The AdminAppClient (`/v1/admin-app/reports`) may return `pending` / `ready` instead of `requested` / `complete`. A normalisation function `normaliseJobStatus(s: string): 'requested' | 'processing' | 'complete' | 'failed'` in `reports.ts` handles this without changing the API clients.

**Alternatives considered**:
- Fix the AdminAppClient to normalise internally — rejected (modifying AdminAppClient is outside scope).
- Accept the mismatch and show raw status — rejected (acceptance criteria require clear status display).

---

## Decision 3: Download flow

**Decision**: Two-step export download using the E24 export endpoints.

**Flow**:
1. When job is COMPLETE, admin clicks "Export CSV" or "Export XLSX"
2. `POST /v1/reports/jobs/{id}/exports` with `{ format }` → returns `ReportExport` with `id` and `status: 'pending'`
3. Poll `GET /v1/reports/exports/{exportId}` every 3s until `status === 'ready'`
4. When ready, `download_url` is populated → `window.open(download_url, '_blank')` to download
5. If `download_url` returned from `getReport` (AdminAppClient path) is available, use it directly without creating a new export

**Rationale**: The E24 contract explicitly uses this two-step pattern (`POST /exports`, then `GET /exports/{id}`). The existing `report-job-detail.tsx` already calls `POST /exports` but doesn't poll for `download_url` — it just fires and forgets. This decision completes the download UX.

**Alternatives considered**:
- Direct download via pre-signed URL on job completion (AdminAppClient path) — partially applicable only when `download_url` is already present on the `ReportJob` object returned by `getReport`. Use this as the fast path.
- Server-side redirect endpoint — not in the E24 contract scope.

---

## Decision 4: Schedule pause/resume

**Decision**: Use `DELETE /v1/reports/schedules/{id}` for "Deactivate" (sets status → inactive); use `PUT /v1/reports/schedules/{id}` for "Re-activate" (sets status → active, preserves all other fields).

**Rationale**: The E24 contract confirms DELETE = soft deactivate (not physical delete). To re-activate, `PUT` with the same schedule data plus `status: 'active'`. The current UI only has a Deactivate button; this decision adds a "Re-activate" button for inactive schedules.

**Note**: The spec's "pause" terminology maps to the E24 "inactive" state. The UI will use the label "Deactivate" for active→inactive and "Re-activate" for inactive→active to match the E24 API semantics.

**Alternatives considered**:
- Add a separate PATCH endpoint — not available in E24 contract; PUT covers this use case.
- Physical delete button — avoided because the E24 DELETE is a soft deactivate; showing "Delete" in the UI would be misleading.

---

## Decision 5: Reconciliation KPI cards — client-side derivation

**Decision**: Derive the four KPI values (Total Transactions, Matched %, Unmatched Count, Exception Amount) client-side from the batch list returned by `GET /v1/admin-app/reconciliation?date_from={start}&date_to={end}`.

**Rationale**: No separate summary endpoint exists in the E24 contract or AdminAppClient. The batch list returns `total_transactions`, `matched_count`, `unmatched_count`, and `discrepancy_amount_minor_units` per batch; summing/averaging these across the filtered result set provides the KPI cards without a new API call.

**KPI derivation**:
- Total Transactions: `sum(batch.total_transactions)`  
- Matched %: `(sum(matched_count) / sum(total_transactions)) * 100`  
- Unmatched Count: `sum(unmatched_count)`  
- Exception Amount: `sum(discrepancy_amount_minor_units) / 100` (BWP)

**Alternatives considered**:
- Add a new summary API endpoint — out of scope (would require a backend E20/E24 change).
- Show raw batch table with no KPIs — rejected; acceptance criteria require KPI cards.

---

## Decision 6: Polling max iterations

**Decision**: Cap job status polling at 36 iterations (3 minutes at 5s interval). After cap, stop polling and show: "Report is still processing — check back later. Refresh this page to see updated status."

**Rationale**: FR-012 from spec. Prevents memory leaks and UX confusion for long-running reports.
