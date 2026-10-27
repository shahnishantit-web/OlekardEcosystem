# Quickstart Validation Guide: P21 — Admin Finance Dashboard & Operations Screens

## Prerequisites

1. Docker stack running: `docker compose up -d`
2. Migrations and seeders up to date:
   ```bash
   docker compose exec api bash -c "REDIS_CLIENT=predis php artisan migrate"
   docker compose exec api bash -c "REDIS_CLIENT=predis php artisan db:seed --class=DevAdminFullPermissionsSeeder"
   docker compose exec api bash -c "REDIS_CLIENT=predis php artisan db:seed --class=DevPaymentProviderSeeder"
   ```
3. Admin app running: `cd apps/admin && npm run dev`
4. Login as `admin@olekard.local` / `Admin123!` at `http://localhost:3000`

---

## Scenario SC-01: Finance Dashboard Loads with KPIs

**Goal:** Verify US1 — dashboard renders KPI tiles, attempt timeline, and backlog.

**Steps:**
1. Navigate to `http://localhost:3000/payments/finance`
2. Observe KPI tiles: "Today's Volume", "Attempts by Status", "Fulfilment Backlog"

**Expected:**
- All KPI tiles render without error
- Currency tiles show BWP amounts (or zero with empty state)
- Fulfilment backlog shows count (may be 0)
- Page shows freshness timestamp

**Pass criteria:** 200 from `GET /api/v1/admin/payment/dashboard-summary`; no console errors.

---

## Scenario SC-02: Stale Data Banner

**Goal:** Verify freshness warning appears after threshold.

**Steps:**
1. Load `/payments/finance`
2. In browser console: `window.__testOverrideFetchedAt = Date.now() - 400000` (override fetch time to 6+ minutes ago)
3. Trigger a re-render (switch tab and return)

**Expected:** Amber "Data may be outdated" banner appears with a refresh button.

**Note:** If time override is not wired, reduce threshold env var to 10 seconds in `.env.local` for testing.

---

## Scenario SC-03: Intent Timeline Drill-Down

**Goal:** Verify US2 — full intent timeline renders with attempts and journal links.

**Steps:**
1. Navigate to `/payments/finance/intents`
2. Click on any intent row
3. Observe timeline: intent created → attempt rows → ledger journal section → fulfilment status

**Expected:**
- Attempt rows show provider_code, status badge, timestamps
- Ledger section shows debit/credit lines with account names (no raw UUIDs as labels)
- Provider reference field shows `****{last4}` or is absent
- "Query Provider Status" button visible if status is `captured`

**Pass criteria:** `GET /api/v1/admin/payment/intents/{id}/timeline` returns 200; no raw secret values visible in page source.

---

## Scenario SC-04: Refund Approval — Approve Flow

**Goal:** Verify US3 — refund approval requires `payment.refunds.approve`; approves correctly.

**Steps:**
1. Seed a pending refund:
   ```bash
   docker compose exec api bash -c "REDIS_CLIENT=predis php artisan db:seed --class=DevPendingRefundSeeder"
   ```
2. Navigate to `/payments/finance/refunds/approvals`
3. Click "Approve" on the pending refund
4. Confirm the action

**Expected:**
- Refund item moves to "approved" state and disappears from the queue
- Toast: "Refund approved"
- `GET /api/v1/admin/payment/refunds` now returns empty or the item shows `approved` status

**Pass criteria:** `POST /api/v1/admin/payment/refunds/{id}/approve` returns 200; ledger entry created.

---

## Scenario SC-05: Refund Approval — Support Role Cannot Approve

**Goal:** Verify that a support-role user sees the queue but not the approve/reject buttons.

**Steps:**
1. Create a support-only user (or temporarily remove `payment.refunds.approve` from admin role via DevAdmin seeder)
2. Log in as that user
3. Navigate to `/payments/finance/refunds/approvals`

**Expected:**
- Page either shows access-denied state (if user lacks `payment.refunds.view` too), or shows refund items without any Approve/Reject buttons
- Attempting `POST /api/v1/admin/payment/refunds/{id}/approve` directly returns 403

---

## Scenario SC-06: Reconciliation Exceptions — Filter and Update

**Goal:** Verify US4 — exceptions list filters work; status update saves.

**Steps:**
1. Navigate to `/payments/finance/reconciliation`
2. Default view shows `status=open` exceptions only
3. Select `type=amount_mismatch` in filter → list updates
4. Click "Update Status" on an open exception → set to `investigating` with note
5. Refresh and verify status persisted

**Expected:**
- Filter params appear in URL query string
- Resolved exceptions are muted/excluded from default view
- `PATCH /api/v1/admin/payment/reconciliation/exceptions/{id}` returns 200

---

## Scenario SC-07: Payout Approval Queue

**Goal:** Verify payout approve-reservation flow works; execute/confirm-settlement shows blocked state.

**Steps:**
1. Navigate to `/payments/finance/payouts`
2. Find a payout in `reservation_pending` status
3. Click "Approve Reservation"
4. Navigate to a payout in `awaiting_approval` — verify no approve button (wrong state)
5. Check that any payout in an execute/settle state shows a "pending" badge, not a button

**Expected:**
- `POST /payout/requests/{id}/approve-reservation` returns 200
- Execute and confirm-settlement routes return 501 when called directly — no UI button triggers them

---

## Scenario SC-08: Reports — Currency Separation

**Goal:** Verify SC-006 — BWP and USD totals are never summed.

**Steps:**
1. Navigate to `/payments/finance/reports`
2. Set date range to last 7 days, leave currency filter as "All"
3. Click "Generate"

**Expected:**
- Report shows separate rows: "BWP: {count} payments, total BWP {amount}"
- No mixed-currency row like "Total: BWP 150 + USD 50"
- If only BWP data exists, only BWP row shown

---

## Scenario SC-09: Export Flow

**Goal:** Verify SC-008 — async export shows progress then download link.

**Steps:**
1. Navigate to `/payments/finance/reports`
2. Click "Export to CSV"
3. Observe progress indicator
4. Wait for export to complete (or mock via direct API call)
5. Click download link

**Expected:**
- Button shows spinner and "Exporting..." text while status is `pending/processing`
- Button is disabled while in-progress (prevents duplicate export)
- On completion: download link appears with CSV filename
- `GET /api/v1/payment/exports/download/{token}` returns CSV file

---

## Scenario SC-10: No Credentials in Any Screen

**Goal:** Verify SC-004 — secrets are never visible.

**Steps:**
1. Open intent timeline for any intent
2. Open browser DevTools → Network tab
3. Check all API responses from `/api/v1/admin/payment/*`
4. Search page HTML source for any string matching known secret patterns

**Expected:**
- No `callback_secret_reference` raw values in API responses
- Provider reference fields show masked format only
- No raw UUIDs used as display labels (account names used instead)

---

## Running Automated Tests

```bash
cd apps/admin
npx jest src/modules/payment/finance --coverage

# Expected: all tests pass, no credential exposure in snapshots
```
