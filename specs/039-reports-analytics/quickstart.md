# Quickstart: ADM-P1-015 — Reports & Analytics

**Date**: 2026-10-14

## Prerequisites

- Docker running: `docker compose up -d`
- Dev server running: `cd wallet-ecosystem/apps/admin && npm run dev`
- Seeded admin account: `admin@olekard.local` / `Admin123!`
- Tests (no Docker needed): `cd wallet-ecosystem/apps/admin && npx jest __tests__/features/reports/p1015-reports-analytics.test.tsx --verbose`

---

## Scenario 1 — Generate on-demand report (US-P1-015-001)

**Steps**:
1. Log in → navigate to `/reports`
2. In "Request New Report" card: select "Transaction Report" type, set Period Start = 7 days ago, Period End = today, Format = CSV
3. Click "Request Report"

**Expected**:
- New row appears in the report list below with status badge "REQUESTED"
- Within 5 seconds badge updates to "PROCESSING"
- Within 60 seconds badge updates to "COMPLETE" and a "Download" button appears

---

## Scenario 2 — Date range validation (US-P1-015-001 edge case)

**Steps**:
1. On `/reports`, in the generate form: set Period Start = today, Period End = yesterday
2. Click "Request Report"

**Expected**: Inline error "Period end must be on or after period start" — no API call made

---

## Scenario 3 — View report job detail and download (US-P1-015-001 + US-P1-015-003)

**Steps**:
1. From the reports list, click "View" on a COMPLETE report job
2. On `/reports/{id}`, verify all metadata is shown
3. Click "Export CSV"

**Expected**:
- Export status shows "Generating export…"
- Within 10 seconds: "Download CSV" link appears
- Clicking link triggers file download in browser

---

## Scenario 4 — Retry a failed report (US-P1-015-003 edge case)

**Steps**:
1. Find a report job with FAILED status in the list, click "View"
2. On the detail page, verify `failure_reason` is displayed
3. Click "Retry"

**Expected**:
- New report job created with same type/filters
- Page navigates or updates to show the new job in REQUESTED state

---

## Scenario 5 — Create a scheduled report (US-P1-015-002)

**Steps**:
1. Navigate to `/reports/schedules`
2. Click "Create Schedule"
3. In the Sheet panel: Name = "Daily Ops Report", Type = "Transaction Report", Cadence = "Daily", Time = "06:00", enable email notification
4. Click "Save"

**Expected**: New schedule appears in list with status "ACTIVE" and next-run date shown

---

## Scenario 6 — Deactivate and re-activate a schedule (US-P1-015-002)

**Steps**:
1. On `/reports/schedules`, find an Active schedule
2. Click "Deactivate" → schedule status changes to "INACTIVE", Re-activate button appears
3. Click "Re-activate" → schedule status changes to "ACTIVE" again

**Expected**: Status badge updates correctly after each action; next-run date shows "—" when inactive and recalculates when re-activated

---

## Scenario 7 — Weekly schedule requires day-of-week (US-P1-015-002)

**Steps**:
1. Open Create Schedule panel, select Cadence = "Weekly"
2. Verify "Day of Week" field appears
3. Leave Day of Week empty, click Save

**Expected**: Validation error "Day of week is required for weekly schedules"

---

## Scenario 8 — Reconciliation summary with date filter (US-P1-015-004)

**Steps**:
1. Navigate to `/reports/reconciliation`
2. Set date range: last 30 days
3. Click "Search"

**Expected**:
- Four KPI cards appear: Total Transactions (number), Matched % (0.0%–100.0%), Unmatched Count (number), Exception Amount (BWP X,XXX.XX)
- Batch table loads below with matching batches
- Each batch row has a "Detail" link

---

## Scenario 9 — Reconciliation empty state (US-P1-015-004)

**Steps**:
1. On `/reports/reconciliation`, set date range for a period with no batches (e.g. future dates)
2. Click "Search"

**Expected**: "No reconciliation batches found for the selected period." message shown; KPI cards show zeros

---

## Unit Tests (no Docker required)

```bash
cd wallet-ecosystem/apps/admin
npx jest __tests__/features/reports/p1015-reports-analytics.test.tsx --verbose
```

**Target**: 20+ tests covering:
- US1: form renders, submit creates job, status normalisation, polling, date validation, permission gate
- US2: schedule list renders, create, deactivate, re-activate, cadence validation
- US3: export buttons enabled/disabled by status, download flow, retry failed
- US4: KPI card derivation, date range filter, empty state

## Regression Check

```bash
cd wallet-ecosystem/apps/admin
npx jest
```

**Target**: All existing 638+ tests continue to pass (4 pre-existing login failures expected).
