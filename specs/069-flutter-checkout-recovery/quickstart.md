# Quickstart Validation Guide: P22 — Shared Flutter Checkout and Recovery

**Date**: 2026-10-26

This guide describes how to validate the shared checkout library end-to-end in a Docker development environment.

---

## Prerequisites

```bash
# Start the full stack
cd wallet-ecosystem
docker compose up -d

# Seed dev data (customers + agents + payment providers)
docker compose exec api php artisan db:seed --class=DevPaymentProvidersSeeder
docker compose exec api php artisan db:seed --class=DevCustomerSeeder
docker compose exec api php artisan db:seed --class=DevAgentSeeder
```

**Dev credentials** (from existing seeders):
- Customer: `+26771000001` / PIN `123456`
- Agent: `+26771000002` / PIN `123456`

---

## Package Setup Validation

```bash
# Verify package resolves from both apps
cd wallet-ecosystem/apps/customer
flutter pub get
flutter analyze --no-fatal-infos

cd ../agent
flutter pub get
flutter analyze --no-fatal-infos
```

Expected: 0 errors, 0 warnings on both apps after migration.

---

## SC-01: Wallet Payment End-to-End (< 60 seconds)

**Scenario**: Customer pays a bill using the shared checkout with OleKard Wallet.

1. Launch customer app in a simulator
2. Navigate to Bills → Pay Bill → select any active bill
3. Checkout opens (shared package entry point)
4. Verify: options screen appears; Wallet balance shown
5. Select Wallet option; see fee disclosure
6. Tap Pay Now; enter PIN via step-up view
7. Verify: payment confirmed; success screen displayed
8. Verify: `payment_pending_intent_id` removed from SharedPreferences

**Pass criteria**: Wallet payment confirmed in < 60s; no `PaymentExpired` unless server confirms expiry.

---

## SC-02: Bank Redirect Return → Correct Outcome (< 30s after return)

**Scenario**: Customer selects FNB redirect; completes payment in mock bank; returns.

```bash
# Get the intent ID from the pending payment
docker compose exec api php artisan tinker --execute="
  \$intent = \App\Modules\Payment\Models\PaymentIntent::latest()->first();
  echo \$intent->id;"
```

1. Checkout opens; select FNB Redirect option
2. Tap "Open Bank Page" — mock bank opens in browser
3. In browser: complete the mock bank payment form
4. Use deep link or back button to return to app
5. App transitions to PollingStatus immediately
6. Within 30s: success screen displayed

**Pass criteria**: App polls backend; outcome confirmed from `/status` not from URL params.

---

## SC-03: Tampered Total Cannot Charge

**Scenario**: Attempt to inject a modified amount by intercepting the intent creation call.

```bash
# Using mitmproxy or Charles: intercept POST /api/v1/payment/intents
# Modify amount_minor in request body to 1 (1 thebe)
```

1. Open checkout; select any option; tap Pay Now
2. Intercept the `POST /api/v1/payment/intents` request
3. Attempt to modify the `amount_minor` field in the request body (the field is not sent — verify)
4. Verify the request body contains ONLY `purpose_type`, `purpose_reference`, `currency_code`

**Pass criteria**: Amount is NOT in the intent creation request body; the server resolves it from `purpose_reference`.

---

## SC-04: App Restart During Redirect → "Checking Status" Not False Success

**Scenario**: Customer starts a bank redirect, then the app is force-killed.

```bash
# Manually inject a pending intent into SharedPreferences for testing
# OR: start redirect flow then kill process
```

1. Start checkout; select FNB Redirect; tap "Open Bank Page"
2. Force-kill the customer app (not a graceful exit)
3. Launch the app fresh
4. Navigate to Bills → Pay Bill → select same bill
5. **Verify**: checkout opens to "Checking payment status" screen (RecoveringPending or RecoveringUnknown)
6. **Verify**: NOT the options screen, NOT a success screen

**Pass criteria**: 100% of forced-restart scenarios show recovery state, never false success.

---

## SC-05: Wallet Insufficient Funds Shown Before Confirm

**Scenario**: Wallet balance below required amount.

```bash
# Set wallet balance below payment amount
docker compose exec api php artisan tinker --execute="
  \$w = \App\Modules\Wallet\Models\Wallet::where('status','active')->first();
  echo 'balance: ' . \$w->balance_minor;"
# If balance > payment amount, temporarily drain it via transfer
```

1. Open checkout for a payment larger than current wallet balance
2. Options screen loads
3. **Verify**: Wallet option shown with balance displayed
4. **Verify**: `hasSufficientBalance: false` from server → option visually disabled
5. **Verify**: Insufficient funds state shown if user attempts to select

**Pass criteria**: InsufficientFunds displayed before any charge; no attempt created.

---

## SC-06: Both Apps Use Shared Library — No Duplicate Logic

**Verification**: Static analysis confirms no duplicate checkout files in either app.

```bash
# After migration, these files should NOT exist:
ls wallet-ecosystem/apps/customer/lib/modules/payment/domain/payment_notifier.dart
# Expected: No such file or directory

ls wallet-ecosystem/apps/agent/lib/modules/payment/domain/payment_notifier.dart
# Expected: No such file or directory

# These imports should point to shared_payment package:
grep -r "package:shared_payment" wallet-ecosystem/apps/customer/lib/modules/
grep -r "package:shared_payment" wallet-ecosystem/apps/agent/lib/modules/
```

**Pass criteria**: 0 checkout implementation files in either app's `modules/payment/` directory.

---

## SC-07: Provider Outage Returns to Option Selection Without Duplicate Attempt

**Scenario**: FNB is marked unavailable; server returns `PROVIDER_UNAVAILABLE`.

```bash
# Mark FNB provider as unavailable
docker compose exec api php artisan tinker --execute="
  \App\Modules\Payment\Models\PaymentProvider
    ::where('code','fnb_redirect')
    ->update(['status'=>'inactive']);"
```

1. Open checkout; select FNB Redirect; tap Pay Now
2. Server returns `PROVIDER_UNAVAILABLE`
3. **Verify**: `ProviderUnavailableAction` handled — checkout returns to OptionsLoaded
4. **Verify**: FNB option shows as unavailable in options list (restrictions populated)
5. **Verify**: Only ONE attempt in the database for this intent

```bash
docker compose exec api php artisan tinker --execute="
  echo \App\Modules\Payment\Models\PaymentAttempt::count();"
# Expect: 1 (the failed attempt, not duplicated)
```

**Pass criteria**: User sees option selection; no duplicate attempt created.

---

## SC-08: Recovery Distinguishes Unknown / Pending / Fulfilling

**Scenario**: Simulate three different recovery states.

```bash
# For RecoveringPending: inject intent with status=processing
docker compose exec api php artisan tinker --execute="
  \$i = \App\Modules\Payment\Models\PaymentIntent::create([...]);
  // Store ID in SharedPreferences manually via test
  echo \$i->id;"
```

1. **RecoveringPending**: Persist intent ID with status `processing` → launch app → verify "payment is being processed" message shown (no success message)
2. **RecoveringUnknown**: Persist intent ID with status `awaiting_action` → launch app → verify "outcome unknown — do not retry" message; no retry button
3. **RecoveringFulfilling**: Persist intent ID with status `fulfilling` → launch app → verify "payment confirmed, crediting your wallet" message

**Pass criteria**: All three states show distinct, non-misleading messages.

---

## Widget Test Suite

```bash
cd wallet-ecosystem/packages/shared-payment
flutter test test/
```

Expected: All tests pass. Key scenarios covered:
- `NextAction.fromJson` correctly parses all 5 action types
- `PaymentNotifier` stays in `PollingStatus` on poll timeout (not `PaymentExpired`)
- `PaymentCheckoutSheet` renders `RecoveringPending` on cold start with persisted intent
- `PaymentCheckoutSheet` blocks provider replacement after attempt submitted
- `PaymentCheckoutSheet` does not treat redirect URL params as payment proof

---

## flutter analyze (CI gate)

```bash
cd wallet-ecosystem/packages/shared-payment && flutter analyze --no-fatal-infos
cd wallet-ecosystem/apps/customer && flutter analyze --no-fatal-infos
cd wallet-ecosystem/apps/agent && flutter analyze --no-fatal-infos
```

Expected: 0 errors in all three.
