# Quickstart: P07 — Durable Idempotency, Concurrency and Recovery

**Date**: 2026-10-21

## Prerequisites

- Docker Compose stack running (`docker compose up -d`)
- Migrations run: `docker exec api php artisan migrate`
- Seeded: `docker exec api php artisan db:seed --class=DevCustomerSeeder`
- Queue workers running: `docker exec -d api php artisan horizon`

---

## SC-01 — Idempotent Intent Creation (Redis Hit)

**Validates**: US1 FR-001/FR-004 — Redis cache returns same response on second call.

```bash
# Create intent — first call
curl -s -X POST http://localhost:8000/v1/payment/intents \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: idem-sc01-001" \
  -H "Content-Type: application/json" \
  -d '{"purpose_type":"bill","purpose_reference":"ref-sc01","organisation_id":"org-001","payer_actor_id":"actor-001","currency_code":"BWP"}' \
  | jq '{id,status}'

# Second call — must return same id, no new DB row
curl -s -X POST http://localhost:8000/v1/payment/intents \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: idem-sc01-001" \
  -H "Content-Type: application/json" \
  -d '{"purpose_type":"bill","purpose_reference":"ref-sc01","organisation_id":"org-001","payer_actor_id":"actor-001","currency_code":"BWP"}' \
  | jq '{id,status}'
```

**Expected**: Both calls return identical `id`. DB has exactly ONE `payment_intents` row and ONE `payment_idempotency_records` row for this key.

---

## SC-02 — DB Fallback After Redis Flush

**Validates**: US1 FR-005 — PostgreSQL fallback after Redis eviction.

```bash
# After SC-01: flush Redis
docker exec redis redis-cli FLUSHALL

# Retry with same key — must still return cached response from DB
curl -s -X POST http://localhost:8000/v1/payment/intents \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: idem-sc01-001" \
  -H "Content-Type: application/json" \
  -d '{"purpose_type":"bill","purpose_reference":"ref-sc01","organisation_id":"org-001","payer_actor_id":"actor-001","currency_code":"BWP"}' \
  | jq '{id,status}'
```

**Expected**: Same `id` returned. No new DB row created. Redis repopulated.

---

## SC-03 — Payload Mismatch Rejection

**Validates**: US1 FR-003 — Key reuse with different body hash → 422.

```bash
curl -s -X POST http://localhost:8000/v1/payment/intents \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -H "Idempotency-Key: idem-sc01-001" \
  -H "Content-Type: application/json" \
  -d '{"purpose_type":"bill","purpose_reference":"ref-DIFFERENT","organisation_id":"org-001","payer_actor_id":"actor-001","currency_code":"BWP"}' \
  | jq '{error_code}'
```

**Expected**: `HTTP 422` with `"error_code": "IDEMPOTENCY_KEY_MISMATCH"`.

---

## SC-04 — Crash Recovery: SUBMITTING → Succeeded

**Validates**: US2 FR-008/FR-009/FR-010.

```bash
# Manually insert a SUBMITTING attempt (simulating crash after T1 commit)
docker exec api php artisan tinker --execute "
  \$attempt = new \App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel();
  \$attempt->id = \Ramsey\Uuid\Uuid::uuid7()->toString();
  \$attempt->payment_intent_id = 'EXISTING_INTENT_ID';
  \$attempt->provider_code = 'wallet';
  \$attempt->status = \App\Modules\Payment\Domain\Enums\PaymentAttemptStatus::Submitting;
  \$attempt->operation_id = \Ramsey\Uuid\Uuid::uuid7()->toString();
  \$attempt->idempotency_key = 'crash-test-' . time();
  \$attempt->initiated_at = now()->subSeconds(120);
  \$attempt->save();
  echo \$attempt->id;
"

# Run crash recovery
docker exec api php artisan payment:recover-crashed-attempts

# Check attempt resolved
docker exec api php artisan tinker --execute "
  \$a = \App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel::find('ATTEMPT_ID');
  echo \$a->status->value;
"
```

**Expected**: Attempt status is no longer `submitting`.

---

## SC-05 — Crash Recovery: No Provider Record → UNKNOWN + ReconciliationException

**Validates**: US2 FR-011 — When provider has no record, transition to UNKNOWN.

```bash
# Same as SC-04 but with a provider_code that doesn't support queryByOperationId
# Verify in DB:
docker exec api php artisan tinker --execute "
  \$exc = \App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel
    ::where('exception_type', 'unknown_outcome')
    ->latest()
    ->first();
  echo \$exc?->id;
"
```

**Expected**: A `ReconciliationException` of type `unknown_outcome` exists for the attempt.

---

## SC-06 — Duplicate Capture Recording

**Validates**: US3 FR-013/FR-014/FR-015.

```bash
# Simulate a duplicate capture callback: call handleOutcome with a different provider_ref
# on an already-succeeded attempt
docker exec api php artisan tinker --execute "
  \$svc = app(\App\Modules\Payment\Application\PaymentOrchestrationService::class);
  \$svc->handleOutcome([
    'payment_intent_id'     => 'SUCCEEDED_INTENT_ID',
    'payment_attempt_id'    => 'SUCCEEDED_ATTEMPT_ID',
    'outcome'               => 'succeeded',
    'provider_reference_id' => 'duplicate-ref-999',
    'correlation_id'        => 'corr-sc06',
  ]);

  \$exc = \App\Modules\Payment\Infrastructure\Models\ReconciliationExceptionModel
    ::where('exception_type', 'duplicate_capture')
    ->latest()
    ->first();
  echo \$exc?->id . ' ' . \$exc?->description;
"
```

**Expected**: A `ReconciliationException` of type `duplicate_capture` is created. Attempt status unchanged (`succeeded`).

---

## SC-07 — Deadlock Retry Returns Success

**Validates**: US4 FR-016/FR-017 — Retries resolve conflict.

```bash
# Run the dedicated concurrency test
docker exec api php artisan test --filter=DeadlockRetryTest
```

**Expected**: All tests pass. Deadlock is simulated, retried, and resolved successfully.

---

## SC-08 — Deadlock Exhaustion Returns 409

**Validates**: US4 FR-018 — 409 CONCURRENT_MODIFICATION after max retries.

```bash
docker exec api php artisan test --filter=ConcurrentModificationTest
```

**Expected**: Test passes; `HTTP 409` with `CONCURRENT_MODIFICATION` code is returned.

---

## Verification Commands

```bash
# PHPStan level 8 — must be 0 errors from P07 code
./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8

# Full P07 unit tests
php artisan test app/Modules/Payment/Tests/Unit/ --filter=Idempotency
php artisan test app/Modules/Payment/Tests/Unit/ --filter=CrashRecovery
php artisan test app/Modules/Payment/Tests/Unit/ --filter=DuplicateCapture
php artisan test app/Modules/Payment/Tests/Unit/ --filter=Deadlock
```
