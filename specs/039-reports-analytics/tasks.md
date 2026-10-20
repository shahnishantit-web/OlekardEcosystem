# Tasks: ADM-P1-015 — Reports & Analytics

**Input**: Design documents from `specs/039-reports-analytics/`

**Prerequisites**: plan.md ✅, spec.md ✅, research.md ✅, data-model.md ✅, contracts/ ✅, quickstart.md ✅

**Organization**: Tasks grouped by user story for independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Maps to user story from spec.md

---

## Phase 1: Setup — Shared API Wrapper

**Purpose**: Create the shared API module that all user stories depend on. Defines TypeScript types and wrapper functions used by all four modified pages.

- [x] T001 Create `wallet-ecosystem/apps/admin/src/modules/reports/api/reports.ts` — export the following TypeScript interfaces and functions:
  - Interfaces: `ReportJobStatus` (type alias: `'requested' | 'processing' | 'complete' | 'failed'`), `ReportJob` (`id: string`, `report_type: string`, `status: string`, `row_count: number | null`, `failure_reason: string | null`, `filters: Record<string, unknown>`, `result_data: Record<string, unknown> | null`, `as_of_at: string | null`, `created_at: string`, `started_at: string | null`, `completed_at: string | null`), `ReportExport` (`id: string`, `job_id: string`, `format: 'csv' | 'excel'`, `status: 'pending' | 'ready' | 'purged'`, `file_size_bytes: number | null`, `row_count: number | null`, `download_url: string | null`, `token_expires_at: string | null`), `ReportSchedule` (`id: string`, `name: string`, `report_type: string`, `cadence: 'daily' | 'weekly' | 'monthly'`, `cadence_time: string`, `cadence_day_of_week: number | null`, `cadence_day_of_month: number | null`, `next_run_at: string | null`, `status: 'active' | 'inactive'`, `recipients: Array<{user_id: string; email?: string}>`, `email_notification_enabled: boolean`, `filters?: Record<string, unknown>`), `ReconciliationSummary` (`total_transactions: number`, `matched_count: number`, `unmatched_count: number`, `exception_amount_bwp: number`, `matched_pct: number`)
  - Function `normaliseJobStatus(raw: string): ReportJobStatus`: maps `'pending'` → `'requested'`, `'ready'` → `'complete'`, anything else passes through as-is (cast to `ReportJobStatus`)
  - Function `submitReportJob(client: AuthenticatedApiClient, payload: { report_type: string; filters: { date_range_start: string; date_range_end: string } }): Promise<ReportJob>`: `POST /v1/reports/jobs` with idempotency key `crypto.randomUUID()`; returns `res.data.data`
  - Function `getReportJob(client: AuthenticatedApiClient, id: string): Promise<ReportJob>`: `GET /v1/reports/jobs/${id}`; returns `res.data.data`
  - Function `requestExport(client: AuthenticatedApiClient, jobId: string, format: 'csv' | 'excel'): Promise<ReportExport>`: `POST /v1/reports/jobs/${jobId}/exports` with `{ format }`; returns `res.data.data`
  - Function `getExport(client: AuthenticatedApiClient, exportId: string): Promise<ReportExport>`: `GET /v1/reports/exports/${exportId}`; returns `res.data.data`
  - Function `updateSchedule(client: AuthenticatedApiClient, scheduleId: string, payload: Partial<ReportSchedule>): Promise<ReportSchedule>`: `PUT /v1/reports/schedules/${scheduleId}` with payload; returns `res.data.data`
  - Function `deriveReconciliationSummary(batches: ReconciliationBatch[]): ReconciliationSummary`: sums `total_transactions`, `matched_count`, `unmatched_count`, sums `discrepancy_amount_minor_units` then divides by 100 for `exception_amount_bwp`, computes `matched_pct = total > 0 ? (matched/total)*100 : 0`; import `ReconciliationBatch` from `@/lib/api/admin-app-client`
  - Import `AuthenticatedApiClient` from `@/lib/api/authenticated-client`

**Checkpoint**: API wrapper created. All downstream tasks can use `reports.ts`.

---

## Phase 2: User Story 1 — Admin generates an on-demand report (US-P1-015-001, P1) 🎯 MVP

**Goal**: Admin can request an on-demand report by type and date range, see its status update in real time, and navigate to the detail page when complete.

**Independent Test**: Navigate to `/reports`, submit a Transaction Report — job appears in list with REQUESTED status, transitions to COMPLETE, "View" link navigates to detail.

- [x] T002 [US1] Modify `wallet-ecosystem/apps/admin/src/modules/reports/pages/report-list.tsx` — update `AdminAppReportForm` component as follows:
  - Add `useRouter` import and `const router = useRouter()` inside the component
  - Pull `hasPermission` from `useAuth()` via destructuring: `const { authenticatedClient, hasPermission } = useAuth()` — guard entire form with `if (!hasPermission('adminapp.reports.generate')) return null`
  - Replace the hardcoded 5-option `<select>` with `REPORT_TYPE_OPTIONS` constant (all 10 types matching the TYPE_OPTIONS filter select already in the file: `transaction_summary`, `wallet_balances`, `agent_commissions`, `kyc_status`, `reconciliation`, `user_report`, `agent_report`, `settlement_report`, `risk_report`, `financial_summary`)
  - Add `periodStart` and `periodEnd` validation before submit: if `periodEnd < periodStart`, set `formError = 'Period end must be on or after period start.'` and return without API call
  - Change submit success path: after `adminClient.createReport(...)` resolves, navigate to `/reports/${res.data.report_id}` using `router.push` instead of setting `pendingReport` and showing an inline widget (the detail page handles polling)
  - Change the 429 error case: if `(err as {status?: number})?.status === 429`, set `formError = 'You already have the maximum number of active report jobs. Wait for one to complete first.'`
  - Remove the `pollStatus` function, `pollRef`, and associated `useEffect` cleanup from `AdminAppReportForm` — polling now happens on the detail page
  - Remove the `pendingReport` state and its JSX block from `AdminAppReportForm`
  - In `ReportListPage` component: add `const { authenticatedClient, hasPermission } = useAuth()` (replace the existing `const { authenticatedClient } = useAuth()`) and add `hasPermission` to the `authenticatedClient &&` condition: render `AdminAppReportForm` only when `authenticatedClient && hasPermission('adminapp.reports.generate')`
  - Fix status normalisation in `statusVariant()`: add a case `case 'ready': return 'success';` so both 'complete' and 'ready' show the green badge

- [x] T003 [US1] Write describe block "US-P1-015-001 Generate On-Demand Report" in `wallet-ecosystem/apps/admin/__tests__/features/reports/p1015-reports-analytics.test.tsx`:
  - File header: mock `@/src/modules/reports/api/reports` with `mockSubmitReportJob`, `mockGetReportJob`, `mockRequestExport`, `mockGetExport`, `mockUpdateSchedule`, `mockDeriveReconciliationSummary` as `jest.fn()`; mock `next/navigation` with `mockRouterPush`; mock `@/lib/api/admin-app-client` AdminAppClient class with `mockCreateReport`, `mockGetReport`, `mockListReconciliation` as `jest.fn()`; mock `@/lib/auth/context` with `useAuth` returning `{ authenticatedClient: {}, hasPermission: jest.fn().mockReturnValue(true) }`; define fixture `MOCK_JOB_COMPLETE` with all required `ReportJob` fields (status: 'complete', etc.)
  - Test (a): renders generate form when user has 'adminapp.reports.generate' permission
  - Test (b): hides generate form when user lacks 'adminapp.reports.generate' permission
  - Test (c): shows date validation error when period end is before period start (without calling createReport)
  - Test (d): calls createReport with correct payload on valid form submit
  - Test (e): redirects to `/reports/{report_id}` on successful submission
  - Test (f): shows 429-specific error message when concurrency limit exceeded
  - Test (g): normalises 'ready' status to success badge variant (statusVariant('ready') === 'success')

**Checkpoint**: On-demand generation form is correct. US1 independently testable.

---

## Phase 3: User Story 3 — Admin downloads a completed report (US-P1-015-003, P1)

**Goal**: Admin can download a completed report via the two-step export flow (POST exports → poll → open download_url). Failed reports show failure reason and a Retry button.

**Independent Test**: Navigate to `/reports/{id}` for a complete job — click "Export CSV" — export status shows "Generating…" → transitions to clickable download link.

- [x] T004 [US3] Modify `wallet-ecosystem/apps/admin/src/modules/reports/pages/report-job-detail.tsx`:
  - Add imports: `requestExport`, `getExport`, `type ReportExport` from `@/src/modules/reports/api/reports`; `useRouter` from `next/navigation`
  - Add state: `exportState: 'idle' | 'pending' | 'ready' | 'failed'` (default `'idle'`), `activeExport: ReportExport | null` (null), `exportPollCount: number` (0)
  - Add state: `retrying: boolean` (false)
  - Remove the existing `requestExport` callback function and `exportLoading` state; replace with the two-step flow below:
  - Add `handleExport(format: 'csv' | 'excel')` async function:
    - Set `exportState = 'pending'`, `activeExport = null`, `exportPollCount = 0`
    - `const exp = await requestExport(authenticatedClient, job.id, format)` → set `activeExport = exp`
    - Start polling interval (every 3000ms): call `getExport(authenticatedClient, exp.id)`, update `activeExport`, if `status === 'ready'` stop polling, set `exportState = 'ready'`, call `window.open(exp.download_url!, '_blank')`; if `status === 'purged'` stop, set `exportState = 'failed'`; if `pollCount >= 20` stop, set `exportState = 'failed'`; increment `exportPollCount`
    - Clean up interval in a `useEffect` cleanup
    - On catch: set `exportState = 'failed'`
  - Update export button section in JSX:
    - Show "Export CSV" and "Export XLSX" buttons only when `isComplete`; disabled when `exportState === 'pending'` or when not complete
    - Below buttons, show conditional status text: `exportState === 'pending'` → `<p role="status">Generating export…</p>`; `exportState === 'ready'` → `<p role="status" className="text-success">Download started.</p>`; `exportState === 'failed'` → `<p role="alert" className="text-error">Export failed. Try again.</p>`
  - Add retry section for failed jobs: when `job.status === 'failed'` (after normalisation), show `job.failure_reason` in a `<p role="alert">` and a "Retry Report" `<Button>` that calls `handleRetry()`
  - Add `handleRetry` async function: sets `retrying = true`, submits `POST /v1/reports/jobs` via `authenticatedClient.post<{data: ReportJob}>('/v1/reports/jobs', { report_type: job.report_type, filters: job.filters })`, on success calls `router.push('/reports/${newJob.id}')`, on failure shows a toast/alert, finally sets `retrying = false`
  - Pull `hasPermission` from `useAuth()` — no additional permission gate needed (viewing detail is already reading; download is gated by job ownership server-side)
  - Fix status normalisation in `statusVariant()`: add case `'ready': return 'success'` here too
  - Replace the hardcoded `<Badge variant={statusVariant(job.status)}>{job.status}</Badge>` with `<Badge variant={statusVariant(job.status)} label={normaliseJobStatus(job.status).toUpperCase()} />`; import `normaliseJobStatus` from `@/src/modules/reports/api/reports`
  - **Add job-status polling cap (FR-012)**: the existing job-status poll loop (if present) or new one MUST stop after 36 iterations at 5s each (~3 minutes); add state `jobPollCount: number` (0), increment on each poll tick, and when `jobPollCount >= 36` stop polling and show inline message: `"This report is taking longer than expected — check back later. "` with a "Refresh" button that reloads the page. Clean up the interval in `useEffect` cleanup. This is independent of the export-download poll (which caps at 20 × 3s).

- [x] T005 [US3] Add describe block "US-P1-015-003 Download Completed Report" to `wallet-ecosystem/apps/admin/__tests__/features/reports/p1015-reports-analytics.test.tsx`:
  - Test (a): Export CSV button is enabled when job status is 'complete'
  - Test (b): Export CSV button is disabled when job status is 'processing'
  - Test (c): calls requestExport with format 'csv' and correct jobId on click
  - Test (d): shows "Generating export…" status while export is pending (after click, before poll resolves)
  - Test (e): shows failure reason text when job status is 'failed'
  - Test (f): shows Retry button when job status is 'failed'
  - Test (g): calls POST /v1/reports/jobs with original report_type and filters on Retry click
  - Test (h): job-status polling stops after 36 iterations and shows "This report is taking longer than expected" message (mock `getReportJob` to always return `status: 'processing'`; advance timers 36 × 5000ms; assert message appears and polling has stopped)
  - Test (i): clicking Export CSV when `job.download_url` is null or absent calls `requestExport` to create a fresh export rather than attempting to use a stale or missing URL (mock job with `download_url: null` and `status: 'complete'`; click Export CSV; assert `requestExport` was called with the job ID)
  - Mock `requestExport` to return `{ id: 'exp-1', status: 'pending', download_url: null, ... }`; mock `getExport` to return `{ id: 'exp-1', status: 'ready', download_url: 'https://dl.example.com/report.csv', ... }`

**Checkpoint**: Download flow correct. US1+US3 both complete and independently testable.

---

## Phase 4: User Story 2 — Admin manages scheduled reports (US-P1-015-002, P2)

**Goal**: Admin can create, deactivate, and re-activate scheduled reports. Create form uses user-supplied date range instead of hardcoded dates.

**Independent Test**: Navigate to `/reports/schedules` — create Daily schedule — deactivate it — re-activate it — verify status badge changes correctly.

- [x] T006 [US2] Modify `wallet-ecosystem/apps/admin/src/modules/reports/pages/report-schedule-list.tsx`:
  - Add import: `updateSchedule` from `@/src/modules/reports/api/reports`
  - Add import: `type ReportSchedule` from `@/src/modules/reports/api/reports`; replace the local `interface ReportSchedule` with the imported type (if shapes match; adjust fields as needed to match the data-model.md definition; ensure `status: 'active' | 'inactive'` not `string`)
  - Add state: `reactivating: string | null` (holds the ID of the schedule currently being re-activated, null otherwise)
  - Add `handleReactivate(schedule: ReportSchedule)` async function:
    - Sets `reactivating = schedule.id`
    - Calls `updateSchedule(authenticatedClient, schedule.id, { ...schedule, status: 'active' })`
    - On success: calls `fetchSchedules()` to re-fetch
    - On catch: sets `setError('Failed to re-activate schedule.')`
    - Finally: sets `reactivating = null`
  - In the schedule table row actions cell: change the existing condition from `s.status === 'active'` to show Deactivate only for active; ADD a new `<Button>` "Re-activate" shown only when `s.status === 'inactive'`, with `onClick={() => void handleReactivate(s)}`, `disabled={reactivating === s.id}`, `aria-label={`Re-activate schedule ${s.name}`}`, shows "Re-activating…" when `reactivating === s.id`
  - Fix `handleCreate`: remove the hardcoded `filters: { date_range_start: '2026-01-01', date_range_end: '2026-12-31' }` from the body — replace with `filters: {}` (empty, let the schedule use defaults) OR add optional form fields `filterStart` and `filterEnd` to `CreateFormData` with empty defaults; if both are provided include `filters: { date_range_start: filterStart, date_range_end: filterEnd }`, otherwise omit `filters` from the payload
  - Add permission gate: `const { authenticatedClient, hasPermission } = useAuth()` (add `hasPermission` to destructuring); wrap Create button, Deactivate button, and Re-activate button visibility in `hasPermission('adminapp.reports.schedule')` — show all controls only when permission is present; the schedule table itself remains visible regardless (read access)
  - Add `handleDeactivate` button label update: add `aria-label={`Deactivate schedule ${s.name}`}` if not already present

- [x] T007 [US2] Add describe block "US-P1-015-002 Manage Scheduled Reports" to `wallet-ecosystem/apps/admin/__tests__/features/reports/p1015-reports-analytics.test.tsx`:
  - Mock `@/lib/api/authenticated-client` GET `'/v1/reports/schedules'` → `{ data: { data: [ACTIVE_SCHEDULE, INACTIVE_SCHEDULE] } }`; DELETE → 204; mock `updateSchedule` from reports module
  - Fixtures: `ACTIVE_SCHEDULE` (`status: 'active'`, all required fields), `INACTIVE_SCHEDULE` (`status: 'inactive'`, all required fields)
  - Test (a): renders schedule list with active and inactive schedules
  - Test (b): shows Deactivate button only for active schedules
  - Test (c): shows Re-activate button only for inactive schedules
  - Test (d): calls DELETE /v1/reports/schedules/{id} on Deactivate click; schedule removed from list on refetch
  - Test (e): calls updateSchedule with `status: 'active'` on Re-activate click
  - Test (f): shows Create Schedule button only when hasPermission('adminapp.reports.schedule') is true
  - Test (g): hides Create/Deactivate/Re-activate when user lacks schedule permission (list still visible)
  - Test (h): weekly cadence shows day-of-week field; monthly shows day-of-month field

**Checkpoint**: Schedule management complete. US2 independently testable.

---

## Phase 5: User Story 4 — Admin views the reconciliation report (US-P1-015-004, P2)

**Goal**: Admin can filter reconciliation batches by date range and see four KPI summary cards above the batch table.

**Independent Test**: Navigate to `/reports/reconciliation` — enter a date range — see KPI cards (Total Transactions, Matched %, Unmatched Count, Exception Amount) and batch table below.

- [x] T008 [US4] Modify `wallet-ecosystem/apps/admin/src/modules/reports/pages/reconciliation-report.tsx`:
  - Add imports: `deriveReconciliationSummary`, `type ReconciliationSummary` from `@/src/modules/reports/api/reports`; `PageHeader` from `@/components/layout/PageHeader`; `Card` from `@/components/ui/Card`
  - Replace the existing `useEffect` auto-fetch with an explicit search: add state `dateFrom: string` (''), `dateTo: string` (''), `searched: boolean` (false), `dateError: string | null` (null), `summary: ReconciliationSummary | null` (null)
  - Remove the auto-fetch (`useEffect(() => { void fetchBatches(); }, [fetchBatches])`) and `page` state — replace with manual search trigger
  - Add `handleSearch` function: validates `dateTo >= dateFrom` (both non-empty required), sets `dateError` if invalid; otherwise sets `searched = true`, `setDateError(null)`, calls `fetchBatches({ date_from: dateFrom, date_to: dateTo })` (pass params to the client call)
  - Update `fetchBatches` to accept params: `fetchBatches(params?: { date_from?: string; date_to?: string })` — pass them to `client.listReconciliation(params ?? {})`; after successful fetch, derive summary via `setStateBatches(res.data.data)` and compute `setSummary(deriveReconciliationSummary(res.data.data))`
  - Add date range filter UI above the batch table: two `<input type="date">` fields labelled "From" and "To", a "Search" `<Button variant="primary">`, and inline error `<p role="alert">` for `dateError`; wrap in `<Card className="mb-6 p-4">`
  - Add `<PageHeader title="Reconciliation Report" />` at the top of the return JSX (replacing the bare `<h1>`)
  - Add KPI summary cards section: render only when `summary !== null`. Display as a 4-column responsive grid of `<Card>` elements (each card has a `<dt>` label and `<dd>` value):
    - Total Transactions: `summary.total_transactions.toLocaleString()`
    - Matched %: `summary.total_transactions > 0 ? summary.matched_pct.toFixed(1) + '%' : '0.0%'`
    - Unmatched Count: `summary.unmatched_count.toLocaleString()`
    - Exception Amount: `'BWP ' + summary.exception_amount_bwp.toFixed(2)`
  - Change empty state condition: show "No reconciliation batches found for the selected period." only when `searched && batches.data.length === 0`; show nothing (no table, no empty state) before the first search
  - Keep existing batch table structure; keep "Detail" link pointing to `/reconciliation/batches/${batch.batch_id}`
  - Remove the existing pagination buttons (the page state no longer exists); show all batches returned by the API (no pagination needed for the summary view)

- [x] T009 [US4] Add describe block "US-P1-015-004 Reconciliation Report" to `wallet-ecosystem/apps/admin/__tests__/features/reports/p1015-reports-analytics.test.tsx`:
  - Mock `AdminAppClient.listReconciliation` to return `{ data: { data: [BATCH_1, BATCH_2] } }` where `BATCH_1 = { batch_id: 'b1', total_transactions: 100, matched_count: 95, unmatched_count: 5, discrepancy_amount_minor_units: 25000, status: 'completed', period_start: '2026-10-01', period_end: '2026-10-07', currency_code: 'BWP', run_at: null, completed_at: null }`, `BATCH_2 = { ...similar... total_transactions: 50, matched_count: 48, unmatched_count: 2, discrepancy_amount_minor_units: 5000, ... }`
  - Mock `deriveReconciliationSummary` to return a calculated summary
  - Test (a): date range form shows on initial load; batch table and KPI cards are NOT shown before search
  - Test (b): shows date validation error when to-date is before from-date; no API call made
  - Test (c): calls listReconciliation with correct date_from/date_to params on Search click
  - Test (d): renders four KPI cards after successful search
  - Test (e): KPI card "Matched %" shows computed percentage from batch list (95+48 / 100+50 = 95.3%)
  - Test (f): shows empty state message "No reconciliation batches found for the selected period." when API returns empty array (and search has been performed)
  - Test (g): each batch row has a Detail link pointing to `/reconciliation/batches/{batch_id}`

**Checkpoint**: Reconciliation report complete with KPI cards and date filtering. All 4 US independently testable.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: TypeScript validation, full regression test, and accessibility review.

- [x] T010 Run `npx tsc --noEmit --skipLibCheck` from `wallet-ecosystem/apps/admin` — fix any TypeScript errors in new/modified files (common issues: missing `?` on optional fields, missing `void` on async handlers, type assertions for status strings)

- [x] T011 Run `npx jest __tests__/features/reports/p1015-reports-analytics.test.tsx --verbose` from `wallet-ecosystem/apps/admin` — verify all tests pass; record pass count in implementation summary

- [x] T012 [P] Verify ARIA accessibility on all modified pages — confirm: date range inputs have `<label>` associations; KPI cards use `<dt>`/`<dd>` within `<dl>`; Export/Retry buttons have `aria-label`; polling/status indicators have `role="status"` and `aria-live="polite"`; error messages have `role="alert"`; all buttons have descriptive labels

- [x] T013 Run full test suite `npx jest` from `wallet-ecosystem/apps/admin` — confirm 638+ existing tests pass (4 pre-existing login failures expected); record total count

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 (T001)**: No dependencies — start immediately
- **Phase 2 US1 (T002–T003)**: Depends on T001
- **Phase 3 US3 (T004–T005)**: Depends on T002 (detail page modifications extend report-job-detail.tsx; imports `normaliseJobStatus` from T001's module); can run in parallel with T003 once T001 is done
- **Phase 4 US2 (T006–T007)**: Depends on T001 only; can run in parallel with Phase 3
- **Phase 5 US4 (T008–T009)**: Depends on T001 only; can run in parallel with Phases 3 and 4
- **Phase 6 Polish (T010–T013)**: Depends on all phases complete

### User Story Dependencies

- **US1 (P1)**: Depends on T001 (API wrapper)
- **US3 (P1)**: Depends on T001 (imports `requestExport`, `getExport`, `normaliseJobStatus`) — effectively depends on T002 for the `report-job-detail.tsx` context
- **US2 (P2)**: Depends on T001 only — independent of US1 and US3
- **US4 (P2)**: Depends on T001 only — independent of all other stories

### Parallel Opportunities

- T002 and T004 touch different files — can be done in parallel after T001
- T003, T005, T007, T009 all write to the SAME test file — must be sequential
- T006 and T008 touch different files — can be done in parallel
- T010 and T012 — different concerns, parallel
- T011 and T013 — T011 subset of T013; run T011 first for fast feedback, then T013 for regression

---

## Parallel Example: Phase 1

```
T001: src/modules/reports/api/reports.ts   ← must complete first
        ↓
T002: report-list.tsx (US1)                ← parallel with T004
T004: report-job-detail.tsx (US3)          ← parallel with T002
T006: report-schedule-list.tsx (US2)       ← parallel with T002, T004, T008
T008: reconciliation-report.tsx (US4)      ← parallel with T002, T004, T006
```

Test file tasks (T003, T005, T007, T009) must run sequentially since they all write to one file.

---

## Implementation Strategy

### MVP First (US1 + US3 — both P1)

1. T001: Create `reports.ts` API wrapper
2. T002: Fix `report-list.tsx` generate form
3. T004: Fix `report-job-detail.tsx` download flow
4. T003 + T005: Write tests for US1 + US3
5. **VALIDATE**: `npx jest __tests__/features/reports/p1015-reports-analytics.test.tsx` → US1+US3 tests pass

### Full Delivery (add US2 + US4)

6. T006: Fix `report-schedule-list.tsx` (add re-activate)
7. T008: Fix `reconciliation-report.tsx` (add date filter + KPI cards)
8. T007 + T009: Write tests for US2 + US4
9. T010–T013: Polish + regression check

---

## Notes

- `reports.ts` is new — all TypeScript types should be `interface` not `type` for extensibility, except `ReportJobStatus` which is a union type
- `ReconciliationBatch` is imported from `admin-app-client.ts` in `deriveReconciliationSummary` — do not re-define it in `reports.ts`
- `normaliseJobStatus()` is a pure function with no side effects — easy to unit test
- The `AdminAppReportForm` redirects to `/reports/${res.data.report_id}` — `report_id` (not `id`) is the field on `AdminAppClient`'s `ReportJob` type; verify field name before implementing
- Schedule `handleDeactivate` calls `DELETE` (soft deactivate per E24 contract — sets status to `inactive`, not physical delete); `handleReactivate` calls `PUT` with the full schedule object plus `status: 'active'`
- Reconciliation KPIs are derived client-side from the batch list — no extra API call; `deriveReconciliationSummary` handles empty arrays gracefully (returns zeros)
- Test file mocks should mock the entire `@/src/modules/reports/api/reports` module so individual functions can be controlled per test
