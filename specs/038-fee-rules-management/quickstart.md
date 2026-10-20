# Quickstart Validation Guide: ADM-P1-014 — Fee Rules Management

## Prerequisites

- Docker stack running: `docker compose up -d` from `wallet-ecosystem/`
- Admin app dev server: `npm run dev` from `wallet-ecosystem/apps/admin`
- Admin login: `admin@olekard.local` / `Admin123!` at `http://localhost:3000/login`
- Admin user has `fees.rules.create`, `fees.rules.view`, `fees.rules.update`, `fees.rules.delete` permissions (run `DevAdminFullPermissionsSeeder` if needed)

## Scenario 1 — Fee Rules List with Row Navigation (US-P1-014-001)

```
1. Navigate to http://localhost:3000/fees/rules
2. Verify: table renders with columns Transaction Type, Fee Type, Fee, Subject, Status, Valid From
3. Verify: filter dropdowns (transaction type, status) are present
4. Click any row in the list
5. Verify: browser navigates to /fees/rules/{id}
6. Verify: detail page loads with the rule's attributes
```

**Expected**: List renders, rows are clickable, detail page shows all rule fields.

---

## Scenario 2 — Create Fee Rule (US-P1-014-001)

```
1. Navigate to http://localhost:3000/fees/rules/create
2. Select Transaction Type: transfer
3. Select Fee Type: percentage
4. Enter Rate: 1.5
5. Enter Currency: BWP
6. Select Subject Type: global
7. Set Valid From: today (any past or present datetime)
8. Click "Create Rule"
9. Verify: redirected to /fees/rules/{new-id}
10. Verify: success toast "Fee rule created"
11. Verify: detail page shows status badge "ACTIVE"
```

**Expected**: Rule created successfully, detail page shown.

---

## Scenario 3 — 409 Conflict on Duplicate Active Rule (US-P1-014-001)

```
1. Ensure an active global BWP transfer rule exists (from Scenario 2)
2. Navigate to http://localhost:3000/fees/rules/create
3. Fill same transaction_type=transfer, currency=BWP, subject_type=global, fee_type=percentage, rate=2.0
4. Click "Create Rule"
5. Verify: error banner appears with text about conflicting rule
6. Verify: no redirect to detail page
```

**Expected**: 409 mapped to human-readable conflict message.

---

## Scenario 4 — Deactivate a Fee Rule (US-P1-014-002)

```
1. Navigate to an active fee rule detail page (/fees/rules/{id} where status=active)
2. Verify: "Deactivate" button is visible
3. Click "Deactivate"
4. Verify: confirmation dialog opens with financial impact warning
5. Click "Confirm Deactivation"
6. Verify: status badge updates to "DEACTIVATED"
7. Verify: success toast fires
8. Verify: "Deactivate" button is no longer present
9. Refresh page
10. Verify: status still shows "DEACTIVATED" (persisted)
```

**Expected**: Rule deactivated, UI reflects new status.

---

## Scenario 5 — Deactivated Rule Read-Only State (US-P1-014-002)

```
1. Navigate to the deactivated rule from Scenario 4
2. Verify: no Deactivate button
3. Verify: informational note: "This rule has been deactivated..."
4. Navigate to http://localhost:3000/fees/rules
5. Filter status: deactivated
6. Verify: the rule appears with "DEACTIVATED" badge
```

**Expected**: Deactivated rule is visible but no actions available.

---

## Scenario 6 — Fee Simulation on Active Rule (US-P1-014-003)

```
1. Navigate to an active percentage fee rule detail page
   (e.g., 0.5% transfer rule created in Scenario 2)
2. Click "Simulate Fee Impact" (or toggle the simulation panel)
3. Verify: simulation panel opens with transaction type pre-filled
4. Enter Amount: 1000  (decimal BWP = 100,000 minor units)
5. Click "Calculate"
6. Verify: fee result shows "BWP 5.00" (0.5% of BWP 1,000)
7. Verify: net amount shows "BWP 995.00"
8. Verify: no min/max fee applied notice
```

**Expected**: Correct fee calculated without posting any transaction.

---

## Scenario 7 — Simulation with Min Fee Applied (US-P1-014-003)

```
1. Create a 0.5% transfer rule with min_fee_minor=100 (BWP 1.00)
2. Navigate to that rule's detail page
3. Open simulation panel
4. Enter Amount: 100  (decimal BWP = 10,000 minor units)
   (0.5% of BWP 100 = BWP 0.50 = 50 minor units, below min of 100)
5. Click "Calculate"
6. Verify: fee shows "BWP 1.00 (minimum fee applied)"
7. Verify: net amount shows "BWP 99.00"
```

**Expected**: Minimum fee floor correctly applied and flagged.

---

## Scenario 8 — No Active Rule (US-P1-014-003)

```
1. Ensure no active fee rule exists for qr_payment in BWP
   (deactivate any existing ones first)
2. Navigate to any fee rule detail page (even a deactivated qr_payment rule)
3. Open simulation panel
4. Change transaction type to qr_payment (if shown)
5. Click "Calculate"
6. Verify: message "No fee rule found — this transaction type currently incurs no fee"
```

**Expected**: Zero-fee result communicated clearly.

---

## Running Unit Tests

```bash
cd wallet-ecosystem/apps/admin
npx jest __tests__/features/fees/p1014-fee-rules-management.test.tsx --verbose
```

**Expected**: All tests pass.

## TypeScript Check

```bash
cd wallet-ecosystem/apps/admin
npx tsc --noEmit --skipLibCheck
```

**Expected**: Zero errors.
