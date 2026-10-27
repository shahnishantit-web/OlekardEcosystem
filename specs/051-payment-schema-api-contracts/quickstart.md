# Quickstart: P02 — Payment Schema and API Contracts

**Purpose**: Runnable validation scenarios to confirm P02 implementation is correct. No live payments required.

**Prerequisites**:
- Docker running: `docker compose up -d`
- Inside API container: `docker compose exec api bash`
- Migrations applied: `php artisan migrate --path=app/Modules/Payment/Infrastructure/Migrations`

---

## Scenario 1 — Multi-State Migration Runs Clean (SC-01)

Verify the additive migration adds the 5 new columns to `payment_intents` without errors on existing data.

```bash
# Run the P02 migrations
php artisan migrate --path=app/Modules/Payment/Infrastructure/Migrations

# Verify columns exist
php artisan db:statement --connection=payment \
  "SELECT column_name, data_type, column_default, is_nullable
   FROM information_schema.columns
   WHERE table_schema = 'payment'
     AND table_name = 'payment_intents'
     AND column_name IN (
       'accounting_status','fulfilment_status','version',
       'fee_quote_minor','fee_quote_provider_code'
     )
   ORDER BY column_name"
```

**Expected**: 5 rows returned. `accounting_status` default `'pending_ledger'`, `fulfilment_status` default `'pending'`, `version` default `'1'`, `fee_quote_minor` default `'0'`, `fee_quote_provider_code` nullable.

```bash
# Verify payment_allocations table exists
php artisan db:statement --connection=payment \
  "SELECT column_name FROM information_schema.columns
   WHERE table_schema = 'payment' AND table_name = 'payment_allocations'
   ORDER BY ordinal_position"
```

**Expected**: 10 columns listed.

---

## Scenario 2 — Rollback is Safe (SC-01)

Verify `down()` methods remove only P02 additions.

```bash
# Roll back P02 migrations only (by batch)
php artisan migrate:rollback --path=app/Modules/Payment/Infrastructure/Migrations --step=3

# Verify original columns still exist
php artisan db:statement --connection=payment \
  "SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = 'payment'
     AND table_name = 'payment_intents'
     AND column_name = 'status'"
```

**Expected**: 1 (original `status` column unaffected).

---

## Scenario 3 — CHECK Constraints Reject Invalid Values (SC-02)

```bash
# This insert MUST fail with a constraint violation
php artisan db:statement --connection=payment \
  "INSERT INTO payment.payment_intents
     (id, reference, purpose_type, purpose_reference, organisation_id,
      payer_actor_id, currency_code, amount_minor, status,
      idempotency_key, correlation_id, expires_at,
      accounting_status)
   VALUES (
     gen_random_uuid(), 'TEST-P02-001', 'test', gen_random_uuid(),
     gen_random_uuid(), gen_random_uuid(), 'BWP', 100, 'pending',
     'idem-test-001', gen_random_uuid(),
     NOW() + INTERVAL '30 minutes',
     'invalid_value'
   )"
```

**Expected**: `ERROR: new row for relation "payment_intents" violates check constraint "payment_intents_accounting_status_check"`

---

## Scenario 4 — Optimistic Lock Concurrency (SC-03)

A PHP unit test (not bash) verifies this. Reference test:

```php
// tests/Unit/PaymentIntentOptimisticLockTest.php
public function test_concurrent_updates_detected(): void
{
    // Create an intent with version = 1
    $intent = PaymentIntentModel::find($existingId);
    $this->assertEquals(1, $intent->version);

    // First update: version 1 → 2
    $affected = DB::connection('payment')
        ->table('payment.payment_intents')
        ->where('id', $existingId)
        ->where('version', 1)
        ->update(['accounting_status' => 'ledger_posted', 'version' => 2]);
    $this->assertEquals(1, $affected); // succeeds

    // Second update: stale version 1 → should find 0 rows
    $affected2 = DB::connection('payment')
        ->table('payment.payment_intents')
        ->where('id', $existingId)
        ->where('version', 1)
        ->update(['accounting_status' => 'ledger_failed', 'version' => 2]);
    $this->assertEquals(0, $affected2); // detects race
}
```

**Expected**: Both assertions pass. 0 affected rows on second update proves optimistic lock works.

---

## Scenario 5 — PaymentAllocation Unique Constraint (Scenario US3)

```bash
# First insert should succeed
php artisan db:statement --connection=payment \
  "INSERT INTO payment.payment_allocations
     (id, payment_intent_id, allocation_type, recipient_type, amount_minor,
      currency_code, status, idempotency_key)
   VALUES (
     gen_random_uuid(), '<EXISTING_INTENT_ID>',
     'olekard_fee', 'olekard', 50, 'BWP', 'allocated', 'idem-alloc-001'
   )"

# Second insert with same idempotency_key MUST fail
php artisan db:statement --connection=payment \
  "INSERT INTO payment.payment_allocations
     (id, payment_intent_id, allocation_type, recipient_type, amount_minor,
      currency_code, status, idempotency_key)
   VALUES (
     gen_random_uuid(), '<EXISTING_INTENT_ID>',
     'olekard_fee', 'olekard', 50, 'BWP', 'allocated', 'idem-alloc-001'
   )"
```

**Expected**: First succeeds; second fails with `ERROR: duplicate key value violates unique constraint "payment_allocations_idempotency_idx"`.

---

## Scenario 6 — OpenAPI Lint (SC-04)

```bash
# From the wallet-ecosystem/services/api directory
cd wallet-ecosystem/services/api

# Run Redocly linter (or spectral)
npx @redocly/cli lint openapi/v1/payment.yaml --format=summary
npx @redocly/cli lint openapi/v1/payment-events.yaml --format=summary
```

**Expected**: `No errors` for both files. Warnings acceptable.

---

## Scenario 7 — PHPStan Clean (SC-07)

```bash
# From wallet-ecosystem/services/api
./vendor/bin/phpstan analyse app/Modules/Payment --level=8 --no-progress
```

**Expected**: `[OK] No errors` — new enum classes, model, and model modifications all pass level 8.

---

## Quick Reference: Files Changed/Created by P02

```
app/Modules/Payment/
├── Domain/
│   └── Enums/
│       ├── PaymentAccountingStatus.php        ← NEW
│       ├── PaymentFulfilmentStatus.php        ← NEW
│       └── PaymentAllocationStatus.php        ← NEW
├── Infrastructure/
│   ├── Migrations/
│   │   ├── 2026_10_21_000001_add_multi_state_to_payment_intents.php    ← NEW
│   │   ├── 2026_10_21_000002_add_fee_snapshot_and_version_to_payment_intents.php ← NEW
│   │   └── 2026_10_21_000003_create_payment_allocations_table.php      ← NEW
│   ├── Models/
│   │   ├── PaymentIntentModel.php             ← MODIFIED ($fillable + casts)
│   │   └── PaymentAllocationModel.php         ← NEW
│   └── Repositories/
│       └── PaymentAllocationRepositoryInterface.php ← NEW (optional, for P02 completeness)
├── Tests/
│   ├── Unit/
│   │   ├── PaymentAccountingStatusTest.php    ← NEW
│   │   ├── PaymentFulfilmentStatusTest.php    ← NEW
│   │   ├── PaymentAllocationStatusTest.php    ← NEW
│   │   └── PaymentIntentOptimisticLockTest.php ← NEW
│   └── Feature/
│       └── PaymentAllocationMigrationTest.php ← NEW

openapi/v1/
├── payment.yaml              ← MODIFIED (NextAction, new components)
└── payment-events.yaml       ← NEW
```
