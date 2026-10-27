# Quickstart Validation Guide: P06 — Intent, Attempt and Payment State Engine

**Date**: 2026-10-21
**Feature**: [spec.md](spec.md)
**Contracts**: [contracts/state-engine-api.md](contracts/state-engine-api.md)

## Prerequisites

- Docker stack running (`docker-compose up -d` from `wallet-ecosystem/`)
- `admin@olekard.local / Admin123!` seeded for admin JWT
- `+26771000001 / PIN 123456` seeded for customer JWT (`DevCustomerSeeder`)
- PHPUnit test suite available: `docker exec api php artisan test`

## Scenario SC-01 — Persist Before Execute

**Spec reference**: FR-03, FR-16, SC-01

**Goal**: Prove that an attempt record in `SUBMITTING` state exists in durable storage before the external adapter is ever called.

**Unit test approach** (no Docker required):
```php
// In tests/Unit/Payment/PersistBeforeExecuteTest.php
// 1. Mock adapter to throw ImmediateException on first call
// 2. Call initiateAttempt()
// 3. Assert: DomainException thrown
// 4. Assert: PaymentAttemptModel exists in DB with status = 'submitting'
// 5. Assert: attempt.operation_id is non-null UUID v7
```

**Expected**: Attempt row exists with `status = 'submitting'`; `operation_id` is set; adapter mock was called exactly once.

---

## Scenario SC-02 — Concurrent Idempotency (Double-Tap)

**Spec reference**: FR-03, SC-02

**Goal**: Two concurrent POSTs with the same `Idempotency-Key` produce exactly one attempt record.

**Unit test approach**:
```php
// In tests/Unit/Payment/ConcurrentAttemptTest.php
// 1. Use DB unique constraint on idempotency_key to simulate race
// 2. Attempt 1: creates record normally
// 3. Attempt 2 (same key): returns idempotent response
// 4. Assert: COUNT(payment_attempts WHERE payment_intent_id = X) = 1
```

**Expected**: Exactly one attempt record; second call returns HTTP 201 with same attempt_id.

---

## Scenario SC-03 — Timeout → UNKNOWN (Never Failed)

**Spec reference**: FR-08, FR-09, SC-03

**Goal**: When the timeout job fires and the provider query returns no definitive status, the attempt moves to `UNKNOWN` — never `failed`.

**Unit test approach**:
```php
// In tests/Unit/Payment/TimeoutToUnknownTest.php
// 1. Create attempt in processing state
// 2. Mock adapter->queryPaymentStatus() to return status=AwaitingAction (ambiguous)
// 3. Invoke PaymentAttemptTimeoutJob::handle()
// 4. Reload attempt from DB
// 5. Assert: attempt.status = 'unknown'
// 6. Assert: intent.status != 'failed' (NOT terminal)
// 7. Assert: ReconciliationException exists with type = 'unknown_outcome'
// 8. Assert: wallet_hold_id is NOT null (hold preserved)
```

**Expected**: Attempt is `unknown`; intent is non-terminal; reconciliation exception created; hold preserved.

---

## Scenario SC-04 — Contradictory Evidence (Succeeded → Failed Callback)

**Spec reference**: FR-10, SC-04

**Goal**: A `failed` callback arriving for an already-`succeeded` attempt creates a `CONTRADICTORY_EVIDENCE` exception and preserves the `succeeded` state.

**Unit test approach**:
```php
// In tests/Unit/Payment/ContradictoryEvidenceTest.php
// 1. Create attempt in succeeded state
// 2. Call handleOutcome() with outcome='failed'
// 3. Assert: InvalidStateTransitionException thrown (or 409 response)
// 4. Assert: attempt.status still = 'succeeded'
// 5. Assert: ReconciliationException exists with type = 'contradictory_evidence'
```

**Expected**: No state change; `CONTRADICTORY_EVIDENCE` exception created.

---

## Scenario SC-05 — UNSAFE_PROVIDER_SWITCH

**Spec reference**: FR-06, SC-05

**Goal**: A new attempt for a different provider is blocked when an existing UNKNOWN attempt exists.

**Unit test approach**:
```php
// In tests/Unit/Payment/UnsafeProviderSwitchTest.php
// 1. Create intent, create attempt with provider_code='bank_abc' → set to 'unknown'
// 2. Call initiateAttempt() with provider_code='bank_xyz' (different provider)
// 3. Assert: UnsafeProviderSwitchException thrown
// 4. Call initiateAttempt() with provider_code='bank_abc' (same provider, fresh key)
// 5. Assert: second call SUCCEEDS (same-provider retry allowed)
```

**Expected**: Different-provider attempt rejected with `UNSAFE_PROVIDER_SWITCH`; same-provider retry proceeds.

---

## Scenario SC-06 — Full Transition Table Validation

**Spec reference**: SC-06

**Goal**: All state transitions NOT in the allowed table are rejected with `INVALID_STATE_TRANSITION`.

**Unit test approach**:
```php
// In tests/Unit/Payment/StateTransitionTableTest.php
// For each (from, to) pair in cross-product of all status values:
//   If in allowed table: assertNoException
//   Else: assertThrows InvalidStateTransitionException
```

**Expected**: 100% of disallowed transitions throw; all allowed transitions pass.

---

## Scenario SC-07 — Status Endpoint Returns Independent Dimensions

**Spec reference**: FR-14, FR-15, SC-07

**Goal**: `GET /v1/payment/intents/{id}/status` returns `accounting_status` and `fulfilment_status` independently.

**Integration test approach**:
```php
// In tests/Feature/Payment/IntentStatusEndpointTest.php
// 1. Create intent, succeed it via handleOutcome()
// 2. GET /v1/payment/intents/{id}/status
// 3. Assert: response.data.status = 'succeeded'
// 4. Assert: response.data.accounting_status = 'posted'
// 5. Assert: response.data.fulfilment_status = 'pending'
// 6. GET /v1/payment/intents/{id}/attempts/{attemptId}
// 7. Assert: attempt.status = 'succeeded'
// 8. Assert: attempt.reconciliation_exception_id = null
```

**Expected**: All three status dimensions returned; reconciliation exception ID is null for clean flow.

---

## Scenario SC-08 — Excess Capture Exception (Non-Blocking)

**Spec reference**: FR-11, SC-08

**Goal**: An excess captured amount triggers a reconciliation exception without blocking the `succeeded` transition.

**Unit test approach**:
```php
// In tests/Unit/Payment/ExcessCaptureTest.php
// 1. Set platform config: payment.excess_capture_tolerance_minor = 0
// 2. Create intent with amount_minor = 10000
// 3. Call handleOutcome() with outcome='succeeded', captured_amount_minor=10001
// 4. Assert: attempt.status = 'succeeded'
// 5. Assert: intent.status = 'succeeded'
// 6. Assert: ReconciliationException exists with type = 'excess_capture'
// 7. Assert: exception.detected_amount_minor = 10001
// 8. Assert: exception.expected_amount_minor = 10000
```

**Expected**: `succeeded` transition completes; `EXCESS_CAPTURE` exception created for operator review.

---

## Integration Quickstart (Docker)

```bash
# 1. Start the stack
docker-compose up -d

# 2. Run P06 unit tests
docker exec api php artisan test --filter=P06

# 3. Run all payment tests
docker exec api php artisan test app/Modules/Payment/Tests/

# 4. PHPStan level 8 on Payment module
docker exec api ./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8
```

**Expected output**:
- All SC-01–SC-08 unit tests PASS
- PHPStan: 0 errors
