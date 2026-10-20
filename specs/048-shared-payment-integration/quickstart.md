# Quickstart Validation Guide: Shared Payment Integration (E48)

**Date**: 2026-10-18 | **Branch**: `048-shared-payment-integration`

Validates the implemented feature end-to-end without full Docker. Each scenario maps to a spec acceptance criterion.

---

## Prerequisites

```bash
# From wallet-ecosystem/services/api/
php artisan migrate
php artisan db:seed --class=PaymentProviderSeeder
php artisan db:seed --class=PaymentPermissionSeeder
```

---

## Smoke Test Scenarios

### SC-01: Option Discovery (S03, FR-E48-007)

**Setup**: OleKard Wallet provider enabled, MockBank provider enabled (dev only)

```bash
# As an authenticated customer (uses existing dev customer token)
curl -s -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  "http://localhost:8000/v1/payment/options?purpose_type=stub_payment&purpose_reference=$STUB_REF&currency=BWP&amount=10000"
```

**Expected**: Response includes `olekard_wallet` and `mock_bank` options; `wallet_balance_minor` present for wallet option; no provider code is hard-coded in the response handler.

**Pass criteria**: HTTP 200; options array has 2 items; OleKard Wallet `sort_order` < MockBank `sort_order`.

---

### SC-02: Wallet Payment — Full Flow (S06, FR-E48-011 through FR-E48-013)

```bash
# Step 1: Create intent
INTENT=$(curl -s -X POST -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"purpose_type":"stub_payment","purpose_reference":"'$STUB_REF'","organisation_id":"'$ORG_ID'","currency_code":"BWP","provider_code":"olekard_wallet","provider_account_id":"'$WALLET_ACCOUNT_ID'"}' \
  http://localhost:8000/v1/payment/intents)
INTENT_ID=$(echo $INTENT | jq -r '.data.payment_intent_id')
echo "Intent: $INTENT_ID, status: $(echo $INTENT | jq -r '.data.status')"

# Step 2: Initiate attempt
ATTEMPT=$(curl -s -X POST -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"provider_code":"olekard_wallet"}' \
  http://localhost:8000/v1/payment/intents/$INTENT_ID/attempts)
echo "Attempt status: $(echo $ATTEMPT | jq -r '.data.status')"
```

**Expected**: Intent created with status `pending`; attempt status `succeeded`; intent status `succeeded`.

**Pass criteria**: No `next_action` in response (wallet is synchronous); `amount_minor` from intent matches server-calculated value; wallet balance decreased by payment amount; ledger entry posted.

---

### SC-03: Idempotency — Same Idempotency Key Twice (S05, FR-E48-006)

```bash
IK=$(uuidgen)
R1=$(curl -s -X POST -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $IK" \
  -H "Content-Type: application/json" \
  -d '{"purpose_type":"stub_payment","purpose_reference":"'$STUB_REF2'","organisation_id":"'$ORG_ID'","currency_code":"BWP","provider_code":"olekard_wallet","provider_account_id":"'$WALLET_ACCOUNT_ID'"}' \
  http://localhost:8000/v1/payment/intents)
R2=$(curl -s -X POST -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $IK" \
  -H "Content-Type: application/json" \
  -d '{"purpose_type":"stub_payment","purpose_reference":"'$STUB_REF2'","organisation_id":"'$ORG_ID'","currency_code":"BWP","provider_code":"olekard_wallet","provider_account_id":"'$WALLET_ACCOUNT_ID'"}' \
  http://localhost:8000/v1/payment/intents)
echo "ID1: $(echo $R1 | jq -r '.data.payment_intent_id')"
echo "ID2: $(echo $R2 | jq -r '.data.payment_intent_id')"
```

**Pass criteria**: Both responses return the same `payment_intent_id`; only one DB record exists; wallet debited exactly once.

---

### SC-04: Mock Bank — Redirect Flow (S08)

```bash
# Step 1: Create intent for mock bank
INTENT_ID=$(curl -s -X POST -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"purpose_type":"stub_payment","purpose_reference":"'$STUB_REF3'","organisation_id":"'$ORG_ID'","currency_code":"BWP","provider_code":"mock_bank","provider_account_id":"'$MOCK_ACCOUNT_ID'"}' \
  http://localhost:8000/v1/payment/intents | jq -r '.data.payment_intent_id')

# Step 2: Initiate attempt — should get redirect URL
ATTEMPT=$(curl -s -X POST -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"provider_code":"mock_bank"}' \
  http://localhost:8000/v1/payment/intents/$INTENT_ID/attempts)
echo "Next action: $(echo $ATTEMPT | jq '.data.next_action')"

# Step 3: Simulate successful mock bank callback (HMAC-SHA256 signed)
PAYLOAD='{"transaction_ref":"'$INTENT_ID'","status":"success","amount":10000,"currency":"BWP"}'
SIG=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$MOCK_SECRET" -binary | xxd -p -c 256)
curl -s -X POST \
  -H "Content-Type: application/json" \
  -H "X-Signature: sha256=$SIG" \
  -d "$PAYLOAD" \
  http://localhost:8000/v1/payment/callbacks/mock_bank
```

**Pass criteria**: Attempt status `awaiting_action` after Step 2; callback returns 202; after job processing, intent status `succeeded`; `payment.confirmed` outbox event published.

---

### SC-05: Duplicate Callback Replay (S10, FR-E48-003)

```bash
# Replay the same callback from SC-04 twice
# (use the same payload + signature from SC-04)
curl -s -X POST -H "Content-Type: application/json" \
  -H "X-Signature: sha256=$SIG" \
  -d "$PAYLOAD" \
  http://localhost:8000/v1/payment/callbacks/mock_bank
```

**Pass criteria**: HTTP 202 on second submission; second callback record has status `duplicate`; no second financial effect; `payment.confirmed` event published exactly once (check outbox table count).

---

### SC-06: Refund — Cumulative Protection (S11, FR-E48-019)

```bash
INTENT_ID="<succeeded-intent-from-SC-02>"

# Refund BWP 60.00 (6000 minor) — should succeed
R1=$(curl -s -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"amount_minor":6000,"reason":"Test refund 1"}' \
  http://localhost:8000/v1/payment/intents/$INTENT_ID/refunds)
echo "Refund 1: $(echo $R1 | jq -r '.data.status')"

# Refund BWP 60.00 again — should FAIL (6000+6000 > 10000)
R2=$(curl -s -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"amount_minor":6000,"reason":"Test refund 2 — should fail"}' \
  http://localhost:8000/v1/payment/intents/$INTENT_ID/refunds)
echo "Refund 2 error: $(echo $R2 | jq -r '.error.code')"
```

**Pass criteria**: R1 status `requested` (or `succeeded` for wallet); R2 HTTP 402 with error code `REFUND_LIMIT_EXCEEDED`.

---

### SC-07: Cross-Organisation Access Denial (S13, FR-E48-022)

```bash
# Attempt to query intent owned by Org A while authenticated as Org B customer
curl -s -H "Authorization: Bearer $ORG_B_CUSTOMER_TOKEN" \
  "http://localhost:8000/v1/payment/intents/$ORG_A_INTENT_ID/status"
```

**Pass criteria**: HTTP 403 or 404 — intent from another organisation never returned.

---

### SC-08: MockBank Production Guard (S07, FR-E48-009)

```bash
# Set APP_ENV=production and attempt to instantiate MockBankPaymentAdapter
# (Run as a PHP artisan tinker command in test environment)
php artisan tinker --execute="app(\App\Modules\Payment\Infrastructure\Adapters\MockBankPaymentAdapter::class)->initiatePayment(null)"
```

**Expected**: `ProductionBlockedException` thrown immediately.

**Note**: This test is run in a test environment with `APP_ENV=production` forced. Never actually run with real production config.

---

### SC-09: Provider Suspension — In-Flight Safety (S03, S05)

```bash
# Step 1: Create intent + initiate attempt (mock bank, awaiting action)
# ... (same as SC-04 Steps 1 & 2)

# Step 2: Suspend the provider via admin API
curl -s -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  "http://localhost:8000/v1/admin/payment/providers/$MOCK_PROVIDER_ID/suspend"

# Step 3: Verify new intent creation fails for suspended provider
NEW_INTENT=$(curl -s -X POST -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" \
  -d '{"provider_code":"mock_bank",...}' \
  http://localhost:8000/v1/payment/intents)
echo "New intent error: $(echo $NEW_INTENT | jq -r '.error.code')"

# Step 4: Verify existing in-flight callback is still processed
# (Post callback for in-flight attempt from Step 1)
```

**Pass criteria**: New intent creation returns error `PROVIDER_UNAVAILABLE`; existing in-flight callback is processed successfully despite provider being suspended.

---

### SC-10: PHPStan Level 8 Clean (S13)

```bash
cd wallet-ecosystem/services/api
./vendor/bin/phpstan analyse app/Modules/Payment --level=8 --no-progress
```

**Pass criteria**: `[OK] No errors`.

---

## Admin UI Smoke Test

**Manual verification required** (Docker-gated for automated):

1. Navigate to `/admin/payment/providers`
2. Verify OleKard Wallet and MockBank appear in the list
3. MockBank displays `DEVELOPMENT MOCK — NOT FOR PRODUCTION` badge
4. Click OleKard Wallet → detail page shows capability flags
5. Click Edit → change display name → save → verify audit log entry
6. Click MockBank → verify "BLOCKED — Documentation Required" appears in documentation gap section
7. Navigate to `/admin/payment/reconciliation/exceptions` → verify list loads (may be empty)

---

## Flutter Widget Test

```bash
cd wallet-ecosystem/apps/customer
flutter test test/modules/payment/payment_checkout_sheet_test.dart --reporter=expanded
```

**Pass criteria**: All 10 state transition tests pass (per flutter-checkout.md test contract).

---

## Regression Verification

```bash
cd wallet-ecosystem/services/api
php artisan test --filter="Funding|Transfer|Withdrawal|Qr|Service" --stop-on-failure
```

**Pass criteria**: All existing E08/E09/E10/E15/E16 tests pass without modification.
