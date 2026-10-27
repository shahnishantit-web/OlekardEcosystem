# Quickstart: P08 — Ledger Acknowledgement and Fulfilment Handoff

**Date**: 2026-10-21

This guide documents the validation scenarios that prove P08 works end-to-end. Each scenario can be executed in a Docker environment with `docker compose up -d` from `wallet-ecosystem/`.

---

## Prerequisites

```bash
# Start the environment
cd wallet-ecosystem
docker compose up -d

# Run DB migrations (includes P08 migration for ledger_repair_at)
docker compose exec api php artisan migrate

# Seed dev data (creates customers, wallets, and a seeded admin)
docker compose exec api php artisan db:seed --class=DevPaymentSeeder
```

---

## SC-01: Balanced journal posted on wallet payment confirmation

**Goal**: Verify that a wallet payment confirmation posts exactly one balanced journal entry.

```bash
# 1. Create a payment intent (wallet provider)
POST /v1/payment/intents
Body: { "amount_minor": 5000, "currency_code": "BWP", "provider_code": "wallet", ... }
# → Intent ID captured as $INTENT_ID

# 2. Create an attempt
POST /v1/payment/intents/$INTENT_ID/attempts
# → Attempt ID captured as $ATTEMPT_ID

# 3. Record outcome (success)
POST /v1/payment/intents/$INTENT_ID/attempts/$ATTEMPT_ID/outcome
Headers: Idempotency-Key: <uuid>
Body: { "outcome": "succeeded", "provider_reference_id": "prov-ref-001" }
# → 204 No Content

# 4. Verify: one journal entry exists for the intent
docker compose exec api php artisan tinker --execute="
  echo app(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class)
    ->entryExists('payment:{$INTENT_ID}:ack') ? 'EXISTS' : 'NOT FOUND';
"
# Expected: EXISTS

# 5. Verify: accounting_status = ledger_posted
docker compose exec api php artisan tinker --execute="
  echo \DB::connection('payment')
    ->table('payment.payment_intents')
    ->where('id', '$INTENT_ID')
    ->value('accounting_status');
"
# Expected: ledger_posted
```

**Scenario passes when**:
- `entryExists('payment:{intentId}:ack')` returns `true`
- `accounting_status = ledger_posted`
- `payment_attempts.ledger_journal_id` is a non-null UUID

---

## SC-02: Ledger outage — capture preserved, accounting pending

**Goal**: Verify that a ledger service failure does not roll back capture.

```bash
# Simulate ledger outage by binding a failing stub in the test or using feature flags
# In unit test: mock LedgerModuleContract::postEntry() to throw \RuntimeException

# After simulating outcome = succeeded with ledger throwing:
# Expected:
#   payment_attempts.status = succeeded  ← capture preserved
#   payment_intents.accounting_status = pending_ledger
#   No journal entry exists (entryExists returns false)
```

**Scenario passes when**:
- `payment_attempts.status = succeeded`
- `accounting_status = pending_ledger`
- `entryExists()` returns `false`

---

## SC-03: Replay idempotency — same intent key, single journal

**Goal**: Verify that replaying the outcome callback does not create a second journal.

```bash
# 1. Record outcome (first time)
POST /v1/payment/intents/$INTENT_ID/attempts/$ATTEMPT_ID/outcome
Headers: Idempotency-Key: <same-key>
# → 204

# 2. Record outcome again (replay)
POST /v1/payment/intents/$INTENT_ID/attempts/$ATTEMPT_ID/outcome
Headers: Idempotency-Key: <same-key>
# → 204

# 3. Verify: LedgerModuleContract returns same journal ID both times
#    (no second INSERT in ledger)
```

**Scenario passes when**:
- Both calls return 204
- Only one journal entry exists for `payment:{intentId}:ack`
- `accounting_status = ledger_posted` (not changed on second call)

---

## SC-04: RepairPendingAccountingJob restores ledger_posted

**Goal**: Verify the repair job can post accounting for a previously-pending intent.

```bash
# 1. Create intent in pending_ledger state (via ledger outage scenario)
#    $INTENT_ID has accounting_status = pending_ledger

# 2. Run the repair scan job
docker compose exec api php artisan queue:work --queue=default --once
# OR dispatch directly:
docker compose exec api php artisan tinker --execute="
  \App\Modules\Payment\Application\Jobs\RepairPendingAccountingJob::dispatch();
"

# 3. Process repair job
docker compose exec api php artisan queue:work --queue=default --once

# 4. Verify
docker compose exec api php artisan tinker --execute="
  echo \DB::connection('payment')
    ->table('payment.payment_intents')
    ->where('id', '$INTENT_ID')
    ->value('accounting_status');
"
# Expected: ledger_posted
```

**Scenario passes when**:
- `accounting_status = ledger_posted`
- `ledger_repair_at` is non-null
- `payment.confirmed` outbox event exists for the intent

---

## SC-05: payment.confirmed event includes allocation_reference

**Goal**: Verify the outbox event contains `allocation_reference = payment_intent_id`.

```bash
docker compose exec api php artisan tinker --execute="
  \$row = \DB::connection('platform')
    ->table('platform.outbox_events')
    ->where('aggregate_id', '$INTENT_ID')
    ->where('event_name', 'payment.confirmed')
    ->first();

  \$payload = json_decode(\$row->payload, true);
  echo \$payload['allocation_reference'] === '$INTENT_ID' ? 'PASS' : 'FAIL';
"
# Expected: PASS
```

---

## SC-06: Admin pending-accounting list returns pending intents

**Goal**: Verify the admin endpoint lists intents with unresolved accounting.

```bash
# Authenticate as admin
GET /v1/admin/payment/intents/pending-accounting
Headers: Authorization: Bearer <admin-token>

# Expected response:
# {
#   "data": [
#     {
#       "id": "<intent-id>",
#       "accounting_status": "pending_ledger",
#       "seconds_since_capture": <N>
#       ...
#     }
#   ],
#   "meta": { "total": 1, "page": 1 ... }
# }
```

**Scenario passes when**:
- HTTP 200 with correct pagination
- `accounting_status` ∈ `{pending_ledger, ledger_failed}` for all returned records
- Access denied (403) when called without `payment.intents.admin` permission

---

## SC-07: Repair exhaustion → ledger_failed + AccountingFailure reconciliation exception

**Goal**: Verify that exhausting max retries transitions to `ledger_failed` and creates a reconciliation exception.

```bash
# Configure max retries = 1 for test
docker compose exec api php artisan tinker --execute="
  // Assume config is database-driven via ConfigurationService
  \DB::connection('platform')
    ->table('platform.configuration')
    ->updateOrInsert(
      ['key' => 'payment.accounting_repair_max_retries'],
      ['value' => '1']
    );
"

# Run repair job against a permanently failing ledger mock
# After exhaustion:

docker compose exec api php artisan tinker --execute="
  echo \DB::connection('payment')
    ->table('payment.payment_intents')
    ->where('id', '$INTENT_ID')
    ->value('accounting_status');
"
# Expected: ledger_failed

docker compose exec api php artisan tinker --execute="
  echo \DB::connection('payment')
    ->table('payment.reconciliation_exceptions')
    ->where('payment_intent_id', '$INTENT_ID')
    ->where('exception_type', 'accounting_failure')
    ->count();
"
# Expected: 1
```

---

## Unit Test Quick Check

```bash
# Run P08 unit tests (no Docker required beyond PHP)
docker compose exec api php artisan test \
  app/Modules/Payment/Tests/Unit/LedgerAcknowledgementTest.php \
  app/Modules/Payment/Tests/Unit/RepairPendingAccountingJobTest.php \
  --stop-on-failure
```

Expected: All tests pass.

---

## References

- [Data model](data-model.md)
- [Admin API contract](contracts/pending-accounting.yaml)
- [Spec](spec.md)
