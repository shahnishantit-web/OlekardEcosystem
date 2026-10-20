# Quickstart — E32 Agent Application

End-to-end validation guide. Prerequisites: Docker stack running (`docker compose up`), Flutter SDK 3.x on path, a seeded agent account.

**Seeded agent credentials**:
- Phone: `+26771000001`
- PIN: `123456` (hashed before transmission)
- Location code: `GAB-001`

**API base URL**: `http://localhost:8000`

---

## Setup

```bash
# From wallet-ecosystem/apps/agent/
flutter pub get
flutter pub run build_runner build --delete-conflicting-outputs

# Run on Android emulator (API 26+)
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000

# Run on iOS simulator
flutter run --dart-define=API_BASE_URL=http://localhost:8000
```

---

## SC-01: Agent Login

**Goal**: Seeded agent logs in and reaches the dashboard.

**Steps**:
1. Launch app → SplashScreen → redirect to LoginScreen
2. Enter phone `+26771000001`
3. Enter PIN `123456` on PinPadWidget
4. Tap Login

**Expected**:
- Dashboard visible within 5 seconds
- Float balance displayed in BWP
- Today's transaction stats shown (zero counts for fresh seed)

**Automated test**: `integration_test/auth_test.dart::SC-01`

---

## SC-02: Agent Dashboard — Low Float Warning

**Goal**: Low-float threshold indicator appears when balance is below threshold.

**Pre-condition**: Seed agent float balance to `400,000 BWP minor units` (below the default threshold of `500,000`).

**Steps**:
1. Login (SC-01)
2. View dashboard

**Expected**:
- Float balance card shows warning colour (amber/red)
- "Request Float Top-Up" shortcut is prominently displayed on dashboard
- Tapping "Request Float Top-Up" navigates to `/float/topup`

---

## SC-03: Cash-In — Full Flow

**Goal**: Agent deposits funds into customer wallet.

**Pre-condition**: Customer seeded with phone `+26772000001`, active wallet.

**Steps**:
1. From dashboard, tap "Cash In"
2. Search by phone `+26772000001`
3. Confirm displayed customer name and masked wallet number
4. Enter amount `500 BWP` (50000 minor units)
5. Review confirmation screen: amount, fee, net credit, float debit
6. Enter PIN on PinPadWidget
7. Tap Confirm

**Expected**:
- Result screen shows reference number `CI-...`
- Receipt available via "Share Receipt" button
- Dashboard float balance decreases by (50000 + fee) minor units when returning to dashboard
- `GET /v1/agent/transactions` includes new cash-in entry

---

## SC-04: Cash-In — Idempotency (Double Submit)

**Goal**: Submitting the same cash-in twice returns one transaction.

**Steps**:
1. Begin cash-in flow (SC-03) but do NOT confirm yet
2. Capture the generated idempotency key from test hook
3. Submit the request twice concurrently (automated test only)

**Expected**:
- Both calls return the same `reference_number`
- Database has exactly one new transaction (verify via `GET /v1/agent/transactions`)
- No duplicate ledger entries

**Automated test**: `test/modules/cash_in/cash_in_idempotency_test.dart`

---

## SC-05: Cash-Out — Full Flow

**Goal**: Agent pays out cash to customer using pickup code.

**Pre-condition**: Customer with active wallet has initiated a withdrawal via the customer app; pickup code `CASH-OUT-TEST01` seeded with amount `200 BWP`.

**Steps**:
1. From dashboard, tap "Cash Out"
2. On PickupCodeScreen, tap "Enter Code" and type `CASH-OUT-TEST01`
3. Confirm displayed customer name, amount `200 BWP`, expiry time
4. Enter PIN on PinPadWidget
5. Tap Confirm

**Expected**:
- Result screen shows reference number `CO-...`
- Receipt available via "Share Receipt"
- Agent float balance increases by (20000 - fee) minor units

---

## SC-06: Cash-Out — Expired Code

**Goal**: Expired pickup code is rejected with a clear error.

**Pre-condition**: Pickup code `CASH-OUT-EXPIRED` seeded with `expires_at` in the past.

**Steps**:
1. Cash-out → enter `CASH-OUT-EXPIRED`

**Expected**:
- Error dialog: "This pickup code has expired."
- No transaction created

---

## SC-07: Customer Registration

**Goal**: Agent registers a new customer.

**Steps**:
1. From dashboard, tap "Register Customer" (or navigate to `/customers/register`)
2. Fill form: first name `Keitumetse`, last name `Masimega`, phone `+26773000099`, DOB `1992-03-20`, ID `920320099`
3. Submit

**Expected**:
- Customer status screen shows `account_status: pending`, `kyc_status: pending`
- `GET /v1/agent/customers/{id}` returns the new customer

---

## SC-08: Float Top-Up Request

**Goal**: Agent submits a float top-up request.

**Steps**:
1. Navigate to Float screen → tap "Request Top-Up"
2. Enter amount `2000 BWP`
3. Enter optional note "Running low this week"
4. Submit

**Expected**:
- Float screen shows "Pending top-up request" indicator
- `GET /v1/agent/float/requests/pending` returns the request with `status: pending`

**Duplicate request check**:
- Attempt to submit a second request without `is_followup: true`
- Expected: Warning dialog shown; submitting with confirmation adds a follow-up note

---

## SC-09: Commission History

**Goal**: Agent views commission breakdown.

**Pre-condition**: At least one commission entry seeded (from SC-03 cash-in).

**Steps**:
1. Navigate to Commissions screen

**Expected**:
- Commission balance displayed with breakdown by type
- Commission history list shows entry with `linked_transaction_reference` matching SC-03 reference number

---

## SC-10: PIN Change

**Goal**: Agent changes PIN; old PIN is rejected on next login.

**Steps**:
1. Navigate to `/security/change-pin`
2. Enter current PIN `123456`
3. Enter new PIN `654321`
4. Confirm new PIN `654321`
5. Logout
6. Attempt login with old PIN `123456`

**Expected**:
- Step 4: "PIN changed successfully" message
- Step 6: Login fails with "Invalid phone or PIN"
- Login with new PIN `654321` succeeds

---

## SC-11: Offline Resilience

**Goal**: Financial writes are blocked when offline; read screens show cached data.

**Steps**:
1. Load transactions list (SC-03 data should be visible)
2. Disable network (airplane mode on device)
3. Attempt cash-in

**Expected**:
- Offline banner appears at top of every screen
- Transactions list still shows previously loaded data with "Last updated: X" indicator
- Cash-in form: tapping Confirm shows "You are offline — please reconnect and try again" dialog
- No transaction is submitted

---

## Fixture Files

Test fixtures are in `test/fixtures/`:

| File | Used by |
|------|---------|
| `agent.json` | auth module tests |
| `dashboard.json` | dashboard provider tests |
| `customer_lookup.json` | cash-in search test |
| `cash_in_result.json` | cash-in result screen test |
| `pickup_code_lookup.json` | cash-out scan test |
| `cash_out_result.json` | cash-out result screen test |
| `agent_transactions.json` | transaction list test |
| `float_balance.json` | float screen test |
| `float_history.json` | float history test |
| `commission_balance.json` | commission screen test |
| `commission_entries.json` | commission history test |
| `notification.json` | notifications test |
