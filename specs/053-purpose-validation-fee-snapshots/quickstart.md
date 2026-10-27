# Quickstart: P04 — Purpose Validation, Quotes and Fee Snapshots

**Feature**: [spec.md](spec.md)
**Date**: 2026-10-21

---

## Prerequisites

1. Docker Compose stack running (`wallet-ecosystem/` dev environment)
2. Migrations run: `php artisan migrate` (includes P04 migrations)
3. Platform Config seeded: `payment.quote_ttl_seconds = 300`
4. Dev stub resolver registered (APP_ENV=local)
5. Admin user seeded: `admin@olekard.local` / `Admin123!`

---

## Scenario SC-01: Create intent and verify quote is created

**Goal**: A valid intent creation call resolves the obligation server-side and returns a quote with correct total.

### Run
```bash
# 1. Login as a test customer (dev environment)
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/customer/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"phone":"+26771000001","pin":"123456"}' | jq -r '.data.access_token')

# 2. Create a payment intent using the dev stub purpose
curl -s -X POST http://localhost:8000/api/v1/payment/intents \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-sc01-$(date +%s)" \
  -d '{
    "purpose_type": "dev_test_purpose",
    "purpose_reference": "test-ref-001",
    "provider_code": "wallet",
    "currency_code": "BWP"
  }' | jq .
```

### Expected result
```json
{
  "data": {
    "payment_intent_id": "<uuid>",
    "status": "pending",
    "amount_minor": 10000,
    "currency_code": "BWP",
    "quote": {
      "version": 1,
      "base_amount_minor": 10000,
      "fee_amount_minor": "<number >= 0>",
      "total_amount_minor": "<base + fee>",
      "quote_status": "active"
    }
  }
}
```

**PASS criteria**: `total_amount_minor = base_amount_minor + fee_amount_minor`; `quote_status = "active"`.

---

## Scenario SC-02: Retrieve quote for existing intent

**Goal**: `GET /payment/intents/{id}/quote` returns the full fee breakdown.

### Run
```bash
INTENT_ID="<id from SC-01>"

curl -s http://localhost:8000/api/v1/payment/intents/$INTENT_ID/quote \
  -H "Authorization: Bearer $TOKEN" | jq .
```

### Expected result
Response contains `fee_snapshot.computed_fee_minor`, `rounding_mode`, `expires_at`, `quote_status: "active"`.

**PASS criteria**: 200 status; `fee_snapshot` is non-null; `total_amount_minor = base_amount_minor + fee_amount_minor`.

---

## Scenario SC-03: Client-supplied amount is ignored

**Goal**: If a client passes an `amount_minor` field, it is silently ignored and the server-resolved amount is used.

### Run
```bash
curl -s -X POST http://localhost:8000/api/v1/payment/intents \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-sc03-$(date +%s)" \
  -d '{
    "purpose_type": "dev_test_purpose",
    "purpose_reference": "test-ref-sc03",
    "provider_code": "wallet",
    "currency_code": "BWP",
    "amount_minor": 999999999
  }' | jq '.data.amount_minor'
```

### Expected result
`10000` (dev stub fixed amount), NOT `999999999`.

**PASS criteria**: Returned `amount_minor == 10000`.

---

## Scenario SC-04: Purpose ownership validation

**Goal**: An actor cannot create an intent for a purpose owned by another actor.

### Run
```bash
# Login as actor 2
TOKEN2=$(curl -s -X POST http://localhost:8000/api/v1/customer/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"phone":"+26771000002","pin":"123456"}' | jq -r '.data.access_token')

# Attempt to create intent for a purpose whose resolver forces payerActorId = actor1
# (DevPurposeStubResolver binds payerActorId to the requesting actor for dev_test_purpose)
# To test ownership, use a resolver that hardcodes a different actor — or
# use an integration test (T-unit). For quickstart, verify the stub returns the
# requesting actor's ID, not a hardcoded one.
curl -s -X POST http://localhost:8000/api/v1/payment/intents \
  -H "Authorization: Bearer $TOKEN2" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-sc04-$(date +%s)" \
  -d '{"purpose_type":"dev_test_purpose","purpose_reference":"sc04","provider_code":"wallet","currency_code":"BWP"}' \
  | jq '.data.payment_intent_id'
```

### Expected result
201 with the intent (dev stub allows any actor). Ownership enforcement is tested in unit tests (SC-N ownership test).

**Note**: Full ownership enforcement requires a resolver that hardcodes a payerActorId different from the caller. Unit test `PurposeValidationTest::test_ownership_mismatch_rejects` covers this (mocked resolver).

---

## Scenario SC-05: Stale quote revalidation — total changed

**Goal**: When a quote has expired AND the re-resolved total differs, the system returns 409 `QUOTE_STALE_CONFIRM_REQUIRED`.

### Run (requires time manipulation or `payment.quote_ttl_seconds = 0`)
```bash
# Set TTL to 0 so every attempt triggers revalidation
php artisan tinker --execute="app(\App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface::class)->set('payment.quote_ttl_seconds', '0');"

# Create intent
INTENT_ID=$(curl -s -X POST http://localhost:8000/api/v1/payment/intents \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Idempotency-Key: sc05-$(date +%s)" \
  -d '{"purpose_type":"dev_test_purpose","purpose_reference":"sc05","provider_code":"wallet","currency_code":"BWP"}' \
  | jq -r '.data.payment_intent_id')

# Modify fee rule mid-flow (via admin) to change fee amount, then attempt:
# (In practice, DevPurposeStubResolver returns same amount — use a test resolver that
# returns a different amount on second call, which is the unit test approach)
# For quickstart: verify that TTL=0 causes re-resolution on every attempt
curl -s -X POST http://localhost:8000/api/v1/payment/intents/$INTENT_ID/attempts \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" -H "Idempotency-Key: sc05-att-$(date +%s)" \
  -d '{"provider_code":"wallet"}' | jq '.error.code // .data.status'
```

### Expected result
If total unchanged: attempt proceeds (status in response).
If total changed: `QUOTE_STALE_CONFIRM_REQUIRED` in error code.

---

## Scenario SC-06: Dev stub blocked in production

### Run (unit test — not Docker)
```bash
cd wallet-ecosystem/services/api
php artisan test app/Modules/Payment/Tests/Unit/DevStubProductionGuardTest.php
```

### Expected result
Test asserting that constructing `DevPurposeStubResolver` in a mocked `production` environment throws `ProductionBlockedException`.

---

## PHPStan
```bash
cd wallet-ecosystem/services/api
./vendor/bin/phpstan analyse app/Modules/Payment --level=8 --no-progress
```
**PASS criteria**: 0 errors.

---

## Unit test suite
```bash
php artisan test app/Modules/Payment/Tests/Unit/ --filter=P04
```
**PASS criteria**: All P04 unit tests pass; 0 failures.
