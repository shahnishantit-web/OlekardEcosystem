# Quickstart Validation Guide — E31 Customer Application

Validates that the Customer Flutter app runs against the local Docker stack and each user story's happy path works end-to-end.

---

## Prerequisites

| Requirement | Version |
|-------------|---------|
| Flutter SDK | 3.x |
| Dart | 3.x |
| Android Studio / Xcode | Latest stable |
| Docker Desktop | 4.x |
| Local backend (wallet-ecosystem Docker stack) | Running |

---

## Step 1: Start the Backend Stack

```bash
cd wallet-ecosystem
docker compose up -d
```

Verify the API is reachable:
```bash
curl http://localhost:8000/api/health
# Expected: { "status": "ok" }
```

---

## Step 2: Install Flutter Dependencies

```bash
cd wallet-ecosystem/apps/customer
flutter pub get
```

Expected output: `Running "flutter pub get" in customer... X packages loaded`

---

## Step 3: Configure Environment

Flutter reads `API_BASE_URL` at compile time via `--dart-define`. For local development:

```bash
# Android emulator (host is 10.0.2.2)
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000

# iOS simulator (host is localhost)
flutter run --dart-define=API_BASE_URL=http://localhost:8000
```

For an already-built Android debug app using the default `http://localhost:8000`,
forward the emulator port before logging in:

```bash
adb -s emulator-5554 reverse tcp:8000 tcp:8000
```

Repeat forwarding after restarting the emulator. Without forwarding, `localhost`
refers to Android itself, so login fails before the PIN prompt. Alternatively,
rebuild using the `10.0.2.2` command above. Physical devices require an accessible
API address supplied through `API_BASE_URL`.

Verify the local Android login connection and that tapping Log In opens the PIN
sheet, without submitting a PIN (the smoke test exercises the PIN fallback path):

```bash
flutter test integration_test/login_connection_test.dart -d emulator-5554 --dart-define=RUN_LOCAL_LOGIN_SMOKE=true
```

To also submit the local seeded customer's PIN and verify the authenticated
session and `/home` navigation, add `--dart-define=RUN_LOCAL_PIN_SMOKE=true`.
This uses the development fixture `+26771000001` / `123456`.

---

## Step 4: Static Analysis

```bash
flutter analyze
# Expected: No issues found! (0 warnings, 0 errors)
```

---

## Step 5: Unit & Widget Tests

```bash
flutter test
# Expected: All tests passed
```

---

## Validation Scenarios

Run each scenario in order. All require the backend stack running in Docker.

---

### SC-01: User Registration (US-CUST-001)

**Goal**: A new customer can register and reach the home screen.

**Steps**:
1. Launch the app (splash screen appears, redirects to login).
2. Tap **Register**.
3. Enter a valid phone number not yet in the system.
4. Tap **Send OTP** → OTP input screen appears within 2 seconds.
5. Check the backend logs for the OTP (or use test OTP `123456` if `APP_ENV=testing`).
6. Enter OTP → profile setup screen appears.
7. Enter first name, last name → PIN creation screen appears.
8. Enter a 6-digit PIN, confirm it.
9. App navigates to Home screen with wallet balance displayed.

**Expected**: Registration completes, home screen shows wallet with KES 0.00 balance.

---

### SC-02: User Login (US-CUST-002)

**Goal**: A registered customer can log in with PIN.

**Steps**:
1. Logout from SC-01 (profile → logout).
2. Tap **Log In** on the login screen.
3. Enter registered phone number, select **PIN login**.
4. Enter 6-digit PIN.
5. App navigates to Home screen.

**Expected**: Home screen appears with correct balance.

---

### SC-03: View Wallet Balance (US-CUST-006)

**Goal**: Wallet balance is visible and accurate on the Home screen.

**Steps**:
1. Log in (SC-02).
2. Observe the balance card on the Home screen.
3. Pull-to-refresh the home screen.
4. Observe the balance card updates.

**Expected**: Balance displayed in KES format (e.g., `KES 1,000.00`). Pull-to-refresh re-fetches from the API. No raw numbers (minor units) visible to the user.

---

### SC-04: View Transaction History (US-CUST-007, US-CUST-008)

**Goal**: Transaction list loads with pagination; tapping a transaction shows detail.

**Steps**:
1. Log in.
2. Navigate to **Transactions** tab (or tap "View All" from home).
3. Scroll down past 20 transactions — the next page loads automatically.
4. Tap any transaction.
5. Transaction detail screen opens with full information.

**Expected**: Transactions listed with correct type, amount, status, and date. Detail screen shows reference number, counterparty, fee breakdown, and timestamp.

---

### SC-05: Add Money (US-CUST-011)

**Goal**: Customer can initiate a bank transfer funding request.

**Steps**:
1. Log in.
2. Tap **Add Money** shortcut on home screen.
3. Select **Bank Transfer** method.
4. Enter amount (e.g., KES 1,000).
5. Tap **Continue** → Confirmation screen shows amount, fee, net.
6. Enter PIN to authorize.
7. Tap **Submit**.

**Expected**: Success screen shows reference number. Wallet balance increases after bank payment simulation (see backend E08 test mode).

**Idempotency check**: Re-submit the same request with the same idempotency key (simulate by tapping Submit twice quickly). Only one funding record should be created.

---

### SC-06: Send Money (US-CUST-012)

**Goal**: Customer can transfer money to another wallet by QR or wallet number.

**Steps**:
1. Log in with a customer that has KES 500+ balance.
2. Tap **Send Money**.
3. Either: (a) tap QR scanner and scan a test QR, or (b) enter wallet number manually.
4. Enter amount (e.g., KES 100).
5. Tap **Continue** → confirmation screen shows recipient name, amount, fee, net debit.
6. Enter PIN to authorize.
7. Tap **Confirm Transfer**.

**Expected**: Success screen shows reference number and new balance. Recipient's wallet balance increases.

---

### SC-07: Withdraw Money (US-CUST-013)

**Goal**: Customer can request cash withdrawal from an agent wallet.

**Steps**:
1. Log in with a customer that has KES 500+ balance.
2. Tap **Withdraw**.
3. Enter an agent wallet number (use a seeded agent wallet).
4. Enter amount (e.g., KES 200).
5. Tap **Continue** → confirmation screen.
6. Enter PIN to authorize.
7. Tap **Confirm**.

**Expected**: Success screen shows a pickup code. Wallet balance decreases by amount + fee.

---

### SC-08: Pay for a Service (US-CUST-014 to US-CUST-017)

**Goal**: Customer can purchase airtime through the services catalog.

**Steps**:
1. Log in.
2. Navigate to **Services** tab.
3. Tap **Airtime** category.
4. Select **Safaricom Airtime** provider.
5. Enter phone number and amount (e.g., KES 50).
6. Tap **Continue** → preview screen shows "KES 50 airtime to +254...".
7. Enter PIN to authorize.
8. Tap **Purchase**.

**Expected**: Success screen shows reference number. Service history shows the new entry.

---

### SC-09: Push Notifications (US-CUST-009)

**Goal**: App displays a push notification when a transfer is received.

**Steps**:
1. Log in on device/emulator.
2. Use the backend admin or seeded test to send a transfer to this customer's wallet.
3. Observe the device notification tray (with app in background).
4. Tap the notification.

**Expected**: App navigates to the relevant transaction detail screen.

---

### SC-10: Biometric and PIN Security (US-CUST-019)

**Goal**: Biometric authentication gates app access; PIN change works.

**Steps**:
1. Log in.
2. Navigate to **Profile → Security**.
3. Toggle on **Use Biometric Login** (if device has fingerprint/Face ID).
4. Lock the app (send to background for 5+ minutes or restart).
5. Reopen the app → biometric prompt appears.
6. Authenticate with biometric → home screen appears.
7. Navigate to **Profile → Security → Change PIN**.
8. Enter current PIN, enter new PIN twice.
9. Tap **Change PIN**.

**Expected**: Biometric login succeeds. PIN change succeeds with a success toast. Using the old PIN to log in fails.

---

### SC-11: Offline Handling (FR-035)

**Goal**: App shows offline banner when network is unavailable; financial writes are blocked.

**Steps**:
1. Log in with cached data visible.
2. Disable device network (airplane mode).
3. Observe Home screen → offline banner appears at top.
4. Attempt to tap **Send Money**.
5. Observe blocking dialog: "You are offline. Please reconnect to perform transactions."
6. Re-enable network.
7. Observe offline banner disappears.

**Expected**: Offline banner visible within 1–2 seconds of disconnection. Financial screens show blocking dialog, not a spinner or crash.

---

### SC-12: Screenshot Prevention (FR-034)

**Goal**: Financial screens cannot be captured via system screenshot.

**Steps**:
1. Navigate to **Wallet** (balance screen).
2. Attempt a system screenshot (volume down + power on Android, or side + top buttons on iOS).
3. Check the screenshot result.

**Expected (Android)**: Screenshot thumbnail shows a black screen or "Can't take screenshot" notice. The phone's gallery does not contain a capture of the balance.

**Expected (iOS)**: Screen appears blank in screenshot output.

---

## Dart Analyze Gate

The following must pass before any release candidate build:

```bash
cd wallet-ecosystem/apps/customer
flutter analyze --fatal-infos
# Must output: No issues found!
```

---

## Integration Test Suite

```bash
# Run all integration tests (requires running emulator or device)
flutter test integration_test/
```

Individual test files map to user stories:
- `integration_test/auth_flow_test.dart` → SC-01, SC-02
- `integration_test/wallet_test.dart` → SC-03, SC-04
- `integration_test/funding_test.dart` → SC-05
- `integration_test/transfer_test.dart` → SC-06
- `integration_test/withdrawal_test.dart` → SC-07
- `integration_test/services_test.dart` → SC-08
- `integration_test/security_test.dart` → SC-10
- `integration_test/offline_test.dart` → SC-11

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| `API_BASE_URL` not resolving | Missing `--dart-define` | Pass `--dart-define=API_BASE_URL=http://...` |
| 401 on all requests | Access token not injected | Check `AuthInterceptor` implementation |
| QR scanner black screen | Camera permission not granted | Check app permissions in device settings |
| Biometric prompt not showing | `local_auth` not initialized | Call `LocalAuthentication().canCheckBiometrics` in init |
| Push notifications not arriving | FCM token not registered | Verify `POST /v1/customer/devices/{id}/push-token` called on login |
| Screenshot prevention not working | Platform channel method not registered | Check `MainActivity.kt` / `AppDelegate.swift` for `MethodChannel` setup |
