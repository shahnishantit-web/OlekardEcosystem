# Implementation Plan: P06 — Intent, Attempt and Payment State Engine

**Branch**: `055-payment-state-engine` | **Date**: 2026-10-21 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/055-payment-state-engine/spec.md`

---

## Summary

P06 wires the payment lifecycle into a financially safe, durable state machine. It refactors `PaymentOrchestrationService.initiateAttempt()` to persist-before-execute (write `SUBMITTING` attempt before calling the adapter), adds `UNKNOWN` as a non-terminal timeout state (replacing `timed_out`), adds `operation_id` / `quote_id` / `quote_version` columns for deduplication and audit, guards against unsafe provider switching, and enforces authoritative evidence checks in `handleOutcome()` (idempotent success replay, `CONTRADICTORY_EVIDENCE` guard, `EXCESS_CAPTURE` exception).

---

## Technical Context

**Language/Version**: PHP 8.4+, Laravel 11

**Primary Dependencies**: Eloquent ORM, Laravel Horizon (queues), Redis (idempotency locks), `Ramsey/Uuid` (UUID v7)

**Storage**: PostgreSQL schema `payment` — `payment_attempts`, `payment_intents`, `reconciliation_exceptions`

**Testing**: PHPUnit, `php artisan test`, PHPStan level 8

**Target Platform**: Linux container (Docker Compose dev stack)

**Project Type**: Web service — Payment module within modular monolith

**Performance Goals**: `initiateAttempt()` p95 < 500 ms (excluding adapter network time); no DB lock held during adapter call

**Constraints**: No DB lock during network I/O (FR-16); persist-before-execute mandatory (FR-03); `UNKNOWN` must not be treated as terminal (FR-08/FR-09)

**Scale/Scope**: Single Payment module; ~15 files modified or created

---

## Constitution Check

| Principle | Status | Notes |
|-----------|--------|-------|
| P1 — Source of Truth | PASS | All changes derived from P06 spec and predecessor work |
| P2 — No TBD invention | PASS | `excess_capture_tolerance_minor` default = 0 is safe disabled state |
| P3 — Config over hard-coding | PASS | Tolerance, TTLs, circuit breaker all use `ConfigurationServiceInterface` |
| P4 — Financial Integrity | PASS | Persist-before-execute; `UNKNOWN` non-terminal; `CONTRADICTORY_EVIDENCE` guard; `EXCESS_CAPTURE` exception; idempotent outcomes |
| P4 — Idempotency | PASS | `Idempotency-Key` on all attempt mutations; `operation_id` for adapter dedup |
| P4 — Concurrency test | PASS | SC-02 concurrent idempotency test required |
| P5 — Auth server-side | PASS | Ownership check on GET status endpoints; no caller-supplied amount |
| P7 — Controllers thin | PASS | All logic in `PaymentOrchestrationService` |
| P8 — UUID v7 | PASS | `operation_id` generated via `Uuid::uuid7()` |
| P9 — Event reliability | PASS | Events published inside DB transaction (existing pattern preserved) |
| P12 — Test-first | PASS | SC-01–SC-08 test scenarios defined before implementation tasks |
| P13 — Observability | PASS | Audit events for all state transitions; correlation IDs throughout |
| P14 — No silent arch changes | PASS | No framework or boundary changes |

---

## Project Structure

### Documentation (this feature)

```text
specs/055-payment-state-engine/
├── plan.md              ← this file
├── research.md          ← Phase 0 output
├── data-model.md        ← Phase 1 output
├── quickstart.md        ← Phase 1 output
├── contracts/
│   └── state-engine-api.md  ← Phase 1 output
└── tasks.md             ← Phase 2 output (/speckit-tasks)
```

### Source Code

```text
wallet-ecosystem/services/api/
├── app/Modules/Payment/
│   ├── Application/
│   │   ├── PaymentOrchestrationService.php          ← MODIFY: persist-before-execute,
│   │   │                                                UNKNOWN guard, evidence checks
│   │   ├── Jobs/
│   │   │   └── PaymentAttemptTimeoutJob.php          ← MODIFY: transition to UNKNOWN state
│   │   └── Services/
│   │       └── ReconciliationExceptionService.php    ← MODIFY: add create() calls
│   ├── Domain/
│   │   ├── Contracts/
│   │   │   └── PaymentAttemptRepositoryInterface.php ← MODIFY: add findUnknownForIntent()
│   │   ├── Enums/
│   │   │   ├── PaymentAttemptStatus.php              ← MODIFY: add Submitting, Unknown
│   │   │   └── ReconciliationExceptionType.php       ← MODIFY: add ContradictoryEvidence, ExcessCapture
│   │   ├── Exceptions/
│   │   │   └── UnsafeProviderSwitchException.php     ← CREATE NEW
│   │   ├── StateMachines/
│   │   │   └── PaymentAttemptStateMachine.php        ← MODIFY: new transitions
│   │   └── ValueObjects/
│   │       └── PaymentInitiateRequest.php            ← MODIFY: add operationId param
│   ├── Http/
│   │   └── Controllers/
│   │       └── PaymentAttemptController.php          ← MODIFY: add status endpoint
│   └── Infrastructure/
│       ├── Models/
│       │   └── PaymentAttemptModel.php               ← MODIFY: add @property for new columns
│       └── Repositories/
│           └── EloquentPaymentAttemptRepository.php  ← MODIFY: findUnknownForIntent, findActiveForIntent update
├── database/migrations/
│   └── 2026_10_21_000001_p06_payment_attempt_state_engine.php  ← CREATE NEW
├── openapi/v1/payment.yaml                                      ← MODIFY: new paths + error codes
└── app/Modules/Payment/Tests/
    └── Unit/
        ├── PersistBeforeExecuteTest.php               ← CREATE
        ├── ConcurrentAttemptTest.php                  ← CREATE
        ├── TimeoutToUnknownTest.php                   ← CREATE
        ├── ContradictoryEvidenceTest.php              ← CREATE
        ├── UnsafeProviderSwitchTest.php               ← CREATE
        ├── StateTransitionTableTest.php               ← CREATE
        └── ExcessCaptureTest.php                      ← CREATE
    └── Feature/
        └── IntentStatusEndpointTest.php               ← CREATE
```

---

## Complexity Tracking

No constitution violations.

---

## Implementation Phases

### Phase 0: Schema + Enum Foundation (blocking all other phases)

1. Create migration `2026_10_21_000001_p06_payment_attempt_state_engine.php`:
   - `ADD COLUMN operation_id UUID NOT NULL DEFAULT gen_random_uuid()` on `payment.payment_attempts`
   - `CREATE UNIQUE INDEX` on `operation_id`
   - `ADD COLUMN quote_id UUID NULL`
   - `ADD COLUMN quote_version INT NULL`
   - Backfill: `UPDATE payment.payment_attempts SET operation_id = gen_random_uuid() WHERE operation_id IS NULL`

2. Update `PaymentAttemptStatus` enum:
   - Add `Submitting = 'submitting'`
   - Add `Unknown = 'unknown'`
   - Update `isTerminal()`: `Unknown` and `TimedOut` return `false`
   - Update `transitions()` map with new cases

3. Update `PaymentAttemptStateMachine::ALLOWED_TRANSITIONS`:
   - Add `submitting` → `['processing', 'awaiting_action', 'succeeded', 'failed', 'unknown']`
   - Add `unknown` → `['succeeded', 'failed', 'expired']`
   - Update `processing` to include `unknown`
   - Update `awaiting_action` to include `unknown`

4. Update `ReconciliationExceptionType` enum:
   - Add `ContradictoryEvidence = 'contradictory_evidence'`
   - Add `ExcessCapture = 'excess_capture'`

5. Add `UnsafeProviderSwitchException.php` domain exception

6. Update `PaymentInitiateRequest` value object: add `?string $operationId = null`

7. Update `PaymentAttemptModel` `@property` PHPDoc for `operation_id`, `quote_id`, `quote_version`; add to `$fillable` and `$casts`

---

### Phase 1: Repository Updates

8. Update `PaymentAttemptRepositoryInterface`:
   - Add `findUnknownForIntent(string $paymentIntentId): ?PaymentAttemptModel`

9. Update `EloquentPaymentAttemptRepository`:
   - `findActiveForIntent()`: include `unknown` and `timed_out` in non-terminal status check
   - Add `findUnknownForIntent()` implementation: query for `status IN ('unknown', 'timed_out')`

---

### Phase 2: Orchestration Service — Persist-Before-Execute Refactor (US2)

The current `initiateAttempt()` order is: adapter call → DB write.

**New order**:

```
Transaction 1:
  - Reload intent with lock
  - Check idempotency (existing attempt replay)
  - Guard: DUPLICATE_ATTEMPT / UNSAFE_PROVIDER_SWITCH
  - Assert intent state transition (Pending → Processing)
  - Revalidate quote (existing logic)
  - Assert circuit closed
  - Generate operationId = Uuid::uuid7()
  - Consume quote
  - WRITE: attempt in status=Submitting
  - UPDATE: intent.status = Processing
  - Audit: payment.attempt.submitting
COMMIT Transaction 1

Network call (outside any DB lock):
  try:
    result = adapter.initiatePayment(request with operationId)
    recordCircuitSuccess()
    newStatus = result.status
  catch Throwable $e:
    recordCircuitFailure()
    newStatus = Failed

Transaction 2:
  - Reload attempt with lock
  - UPDATE: attempt.status = newStatus
  - if newStatus == Failed: release wallet hold, update intent to retriable state
  - Audit: payment.attempt.initiated or payment.attempt.failed
  - Dispatch PaymentAttemptTimeoutJob
COMMIT Transaction 2
```

Key constraint: `wallet hold` is placed inside Transaction 1 (before the adapter call) so that hold + attempt record are atomic. The wallet module's `placeHold()` must be called in Transaction 1.

---

### Phase 3: UNSAFE_PROVIDER_SWITCH Guard (US2 FR-06/FR-07)

In `initiateAttempt()` Transaction 1, after the idempotency check:

```php
$unknownAttempt = $this->attemptRepository->findUnknownForIntent($intentId);
if ($unknownAttempt !== null && $unknownAttempt->provider_code !== $providerCode) {
    throw new UnsafeProviderSwitchException($intentId, $unknownAttempt->provider_code, $providerCode);
}
```

Same-provider retry (same `provider_code`, new `idempotency_key`) is allowed — the `DuplicateAttemptException` check applies only when the existing attempt is not `UNKNOWN`.

---

### Phase 4: Timeout Job — UNKNOWN State (US3)

Refactor `PaymentAttemptTimeoutJob::handle()`:

1. If attempt is terminal → return (no change, existing behaviour).
2. Query adapter for provider status.
3. If `Succeeded` → `handleOutcome(succeeded)` (existing path).
4. If `Failed` → `handleOutcome(failed)` (existing path).
5. If any other status (including `Initiated`, `Processing`, null result) → **transition attempt to `Unknown`**:
   - DB transaction: `attempt.status = Unknown`, intent status unchanged (must NOT be terminal)
   - Create `ReconciliationException(UnknownOutcome, ...)`
   - Audit: `payment.attempt.unknown`
   - Do NOT release wallet hold

---

### Phase 5: handleOutcome — Evidence Checks + Status Dimensions (US3/US5)

Extend `handleOutcome()` with authoritative evidence guards:

**Guard A — Idempotent success replay**:
```php
if ($attempt->status === PaymentAttemptStatus::Succeeded
    && $newAttemptStatus === PaymentAttemptStatus::Succeeded
    && $attempt->provider_reference_id === $request['provider_reference_id']
) {
    return; // idempotent, no re-posting
}
```

**Guard B — CONTRADICTORY_EVIDENCE**:
```php
if ($attempt->status->isTerminal() && $newAttemptStatus !== $attempt->status) {
    $this->reconciliationExceptionService->create(
        ReconciliationExceptionType::ContradictoryEvidence,
        [...]
    );
    throw new InvalidStateTransitionException(...);
}
```

**Guard C — EXCESS_CAPTURE**:
```php
$tolerance = (int) ($this->config->getOrDefault('payment.excess_capture_tolerance_minor', 0) ?? 0);
$capturedAmountMinor = (int) ($request['captured_amount_minor'] ?? $intent->amount_minor);
if ($newAttemptStatus === PaymentAttemptStatus::Succeeded
    && $capturedAmountMinor > $intent->amount_minor + $tolerance
) {
    $this->reconciliationExceptionService->create(
        ReconciliationExceptionType::ExcessCapture,
        [
            'payment_intent_id'   => $intentId,
            'payment_attempt_id'  => $attemptId,
            'detected_amount_minor' => $capturedAmountMinor,
            'expected_amount_minor' => $intent->amount_minor,
            'currency_code'       => $intent->currency_code,
        ]
    );
    // NOTE: succeeded transition still proceeds despite excess capture
}
```

**Status dimension update** (on succeeded):
```php
$intent->accounting_status  = PaymentAccountingStatus::Posted;
$intent->fulfilment_status  = PaymentFulfilmentStatus::Pending;
```

On failed/cancelled:
```php
$intent->accounting_status = PaymentAccountingStatus::Void;
$intent->fulfilment_status = PaymentFulfilmentStatus::NotRequired;
```

---

### Phase 6: Status API Endpoints (US4)

Update `PaymentAttemptController`:

1. `GET /v1/payment/intents/{intentId}/attempts/{attemptId}` — return `operation_id`, `quote_id`, `quote_version`, `reconciliation_exception_id`
2. `GET /v1/payment/intents/{intentId}/status` — return `accounting_status`, `fulfilment_status`, `latest_attempt`

Authorization: caller must match `intent.payer_actor_id` or have `payment:admin` permission.

---

### Phase 7: OpenAPI Updates

Add to `openapi/v1/payment.yaml`:
- Error codes: `UNSAFE_PROVIDER_SWITCH`, `DUPLICATE_ATTEMPT`, `CONTRADICTORY_EVIDENCE`, `EXCESS_CAPTURE`, `ATTEMPT_NOT_FOUND`
- `GET /v1/payment/intents/{intentId}/status` path with response schema including `accounting_status`, `fulfilment_status`
- Update attempt response schema: add `operation_id`, `quote_id`, `quote_version`, `reconciliation_exception_id`

---

### Phase 8: Tests (SC-01 through SC-08)

Create unit and feature tests per [quickstart.md](quickstart.md):
- `PersistBeforeExecuteTest` (SC-01)
- `ConcurrentAttemptTest` (SC-02)
- `TimeoutToUnknownTest` (SC-03)
- `ContradictoryEvidenceTest` (SC-04)
- `UnsafeProviderSwitchTest` (SC-05)
- `StateTransitionTableTest` (SC-06)
- `IntentStatusEndpointTest` (SC-07)
- `ExcessCaptureTest` (SC-08)

---

### Phase 9: PHPStan Level 8 Pass

Run `./vendor/bin/phpstan analyse app/Modules/Payment/ --level=8`.

Anticipated issues:
- New `@property` annotations needed for `PaymentAttemptModel` (new columns)
- `UnsafeProviderSwitchException` must have typed constructor params
- `handleOutcome()` `$request` array shape needs `@param` phpDoc or typed input DTO

---

## Dependencies Between Phases

```
Phase 0 (Schema + Enums)
  └── Phase 1 (Repository)
        └── Phase 2 (Orchestration Service — persist-before-execute)
              ├── Phase 3 (UNSAFE_PROVIDER_SWITCH guard)
              ├── Phase 4 (Timeout Job → UNKNOWN)
              └── Phase 5 (handleOutcome evidence checks)
                    └── Phase 6 (Status Endpoints)
                          └── Phase 7 (OpenAPI)
                                └── Phase 8 (Tests)
                                      └── Phase 9 (PHPStan)
```

---

## Definition of Done

- [ ] Migration applied; `operation_id`, `quote_id`, `quote_version` columns exist
- [ ] `PaymentAttemptStatus` has `Submitting` and `Unknown` cases; `isTerminal()` returns false for both
- [ ] `initiateAttempt()` writes `SUBMITTING` attempt before adapter call (DB-committed first)
- [ ] `initiateAttempt()` passes `operationId` to adapter via `PaymentInitiateRequest`
- [ ] `UNSAFE_PROVIDER_SWITCH` guard rejects different-provider attempt when UNKNOWN exists
- [ ] Timeout job transitions attempt to `Unknown`, not to `failed`, when outcome is ambiguous
- [ ] `handleOutcome()` is idempotent for duplicate succeeded callbacks
- [ ] `handleOutcome()` raises `CONTRADICTORY_EVIDENCE` for failed-on-succeeded
- [ ] `handleOutcome()` raises `EXCESS_CAPTURE` for over-capture (non-blocking)
- [ ] `handleOutcome()` updates `accounting_status` and `fulfilment_status` on intent
- [ ] GET status endpoints return all three dimensions + `reconciliation_exception_id`
- [ ] All 8 SC unit/feature tests pass
- [ ] PHPStan level 8: 0 errors on `app/Modules/Payment/`
- [ ] OpenAPI updated with new error codes and paths
