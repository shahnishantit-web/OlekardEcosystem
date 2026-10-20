# Quickstart: ADM-P1-016 — Audit Log & Compliance

## Prerequisites

- Admin app running: `cd wallet-ecosystem/apps/admin && npm run dev`
- Seeded admin user: `admin@olekard.local` / `Admin123!`
- RBAC: ensure seeded admin role has `adminapp.audit.read`, `adminapp.compliance.read`, `adminapp.compliance.config`

## SC-01 — Audit log loads in reverse-chronological order

1. Navigate to `http://localhost:3000/audit`
2. **Expected**: Page renders with columns "Occurred At", "Event Type", "Actor", "Affected Entity", "IP Address", "Correlation ID"
3. **Expected**: Events are ordered newest first (most recent `occurred_at` at top)
4. **Expected**: No Edit or Delete buttons visible anywhere on the page

## SC-02 — Audit log filters work

1. On the audit log page, select "Event Type" → type any known event type (e.g., `wallet.freeze.applied`)
2. Click "Apply Filters"
3. **Expected**: Only events matching that type are shown
4. Set a date range > 90 days, click "Apply Filters"
5. **Expected**: Error message "Date range must be 90 days or less" shown, no API call made

## SC-03 — Audit event detail drawer has no edit/delete

1. On the audit log page, click "View" on any row
2. **Expected**: Detail drawer slides in with full event data, integrity badge visible
3. **Expected**: Zero edit or delete buttons in the drawer

## SC-04 — RBAC gate on audit log

1. Log in as a user WITHOUT `adminapp.audit.read`
2. Navigate to `http://localhost:3000/audit`
3. **Expected**: "Access denied" message displayed; no audit data fetched

## SC-05 — Compliance dashboard loads with urgency colour-coding

1. Navigate to `http://localhost:3000/compliance`
2. **Expected**: Regulatory Checklist section renders with green/amber/red badges
3. **Expected**: Open Compliance Items section shows items with urgency badges
4. **Expected**: Retention Deadlines section shows policies with due dates and urgency badges

## SC-06 — Retention policy edit confirmation dialog

1. Navigate to `http://localhost:3000/compliance/retention`
2. Click "Edit" on any policy row
3. Modify "Minimum Retention Days" to a new value
4. Click "Update Policy"
5. **Expected**: Confirmation dialog appears showing "Before: X days → After: Y days"
6. Click "Confirm"
7. **Expected**: Policy saved, success message shown, table refreshes
8. Click "Edit" again, change a value, click "Update Policy", then "Cancel"
9. **Expected**: No API call made, form resets

## SC-07 — Hold-period guard on retention policies

1. In the retention policies table, find a policy with a non-null `enforcement_hold_until` in the future
2. **Expected**: The "Edit" button for that row is disabled (greyed out or absent)

## Running Tests

```bash
cd wallet-ecosystem/apps/admin
npm test -- --testPathPattern=p1016-audit-log-compliance
```

**Expected**: All tests pass; 0 failures

## TypeScript Check

```bash
cd wallet-ecosystem/apps/admin
npx tsc --noEmit
```

**Expected**: 0 errors
