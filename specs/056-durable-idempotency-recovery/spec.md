# Feature Specification: P07 — Durable Idempotency, Concurrency and Recovery

**Feature Branch**: `056-durable-idempotency-recovery`

**Created**: 2026-10-21

**Status**: Draft

**Input**: P07 — Durable idempotency concurrency and recovery (implement mode). Required predecessors: P06 (COMPLETE/RELEASE READY).

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — HTTP-Layer Idempotency with Redis Acceleration (Priority: P1)

A mobile customer taps "Pay" twice in quick succession (or the network retries a dropped request). Both requests carry the same `Idempotency-Key` header and identical body. The second request must return the exact same response as the first — without creating a second payment intent, a second attempt, or a second ledger posting — regardless of whether the first response was received by the client.

Additionally, the idempotency record must survive Redis eviction: when a client retries a day later after Redis has expired the key, the system falls back to the durable PostgreSQL record and still returns the cached response.

**Why this priority**: Without this, network retries and double-taps cause duplicate charges — the most critical financial safety failure in a payment system.

**Independent Test**: Can be fully tested by calling `POST /v1/payment/intents` twice with the same `Idempotency-Key` header and verifying: (a) only one intent row exists in the DB; (b) both responses are identical (same `id`); (c) flushing Redis and retrying still returns the cached response from DB; (d) changing the request body on the third call returns `HTTP 422` with code `IDEMPOTENCY_KEY_MISMATCH`.

**Acceptance Scenarios**:

1. **Given** a `POST /v1/payment/intents` request with `Idempotency-Key: key-A` succeeds, **When** the same request (same body, same actor) is submitted again with `Idempotency-Key: key-A`, **Then** the response status and body are identical to the original response (e.g., `HTTP 201` for a newly created intent), and no new DB record is created.

2. **Given** an idempotency record exists in PostgreSQL but not in Redis (cache expired), **When** the request is retried with the same key, **Then** the system reads the response from PostgreSQL, repopulates Redis, and returns the correct cached response.

3. **Given** a request with `Idempotency-Key: key-A` has already been processed, **When** a new request arrives with `Idempotency-Key: key-A` but a different request body, **Then** the system returns `HTTP 422` with error code `IDEMPOTENCY_KEY_MISMATCH` and rejects the request.

4. **Given** a request with `Idempotency-Key: key-A` is in-flight (being processed), **When** a concurrent duplicate arrives, **Then** the second request waits briefly and returns the same result as the first (no second execution).

---

### User Story 2 — Crash Recovery for SUBMITTING Attempts (Priority: P2)

A payment attempt was written to the database in `SUBMITTING` state (Transaction 1 committed) but the server process died before calling the provider or before recording the provider's response (Transaction 2 never committed). The attempt is stuck in `SUBMITTING` indefinitely. An automated recovery process must detect this, query the provider using the durable `operation_id`, and apply the definitive outcome — without risk of triggering a second charge.

**Why this priority**: Without crash recovery, a server crash between persist-before-execute and the adapter call leaves the customer in an unknown state indefinitely. This is operationally unacceptable for financial transactions.

**Independent Test**: Can be fully tested by: (a) creating an attempt in `SUBMITTING` state with a past `initiated_at` timestamp; (b) running the recovery job; (c) verifying the attempt transitions to the correct terminal state or to `unknown` (if provider has no record); (d) verifying no second financial effect is produced.

**Acceptance Scenarios**:

1. **Given** an attempt has been in `SUBMITTING` state for longer than the configured crash-recovery threshold, **When** the recovery job runs, **Then** the job calls the adapter's `queryPaymentStatus()` with the attempt's `operation_id`, and applies the result via the existing outcome handler.

2. **Given** the provider confirms success for a SUBMITTING attempt, **When** the recovery job processes it, **Then** the attempt transitions to `succeeded`, the intent transitions to `succeeded`, the ledger entry is posted, and a `payment.confirmed` event is published — exactly once.

3. **Given** the provider has no record of the `operation_id` (crash happened before network call reached provider), **When** the recovery job processes it, **Then** the attempt transitions to `failed`, the intent is reset, and the wallet hold (if any) is released.

4. **Given** the adapter does not support `queryPaymentStatus()` (missing `QueryPayment` capability), **When** the recovery job encounters a stuck SUBMITTING attempt, **Then** the attempt is transitioned to `unknown` (cannot be resolved automatically), a `ReconciliationException(UnknownOutcome)` is created, and an operator alert is emitted.

---

### User Story 3 — Duplicate Capture Recording (Priority: P2)

A provider sends a second webhook notification for an already-succeeded payment — this time with a different `provider_reference_id`. This is a genuine double-capture: the provider charged the customer twice. The system must detect this pattern, record it as a reconciliation exception requiring operator-driven compensation, and return a clean response to the caller — without treating it as a normal idempotent replay and without suppressing the evidence of the duplicate financial effect.

**Why this priority**: Suppressing duplicate capture evidence is a financial integrity violation. Operators must know when a customer has been charged twice so they can initiate compensation.

**Independent Test**: Can be fully tested by calling `handleOutcome()` with `outcome=succeeded` and a `provider_reference_id` that differs from the already-recorded ref on a `succeeded` attempt. Verify: (a) a `ReconciliationException(DuplicateCapture)` is created with both provider references; (b) the attempt status is NOT changed; (c) the call returns without throwing.

**Acceptance Scenarios**:

1. **Given** a `PaymentAttempt` is in `succeeded` state with `provider_reference_id = ref-001`, **When** `handleOutcome()` is called with `outcome=succeeded` and `provider_reference_id = ref-002` (a different, non-null reference), **Then** a `ReconciliationException` of type `DuplicateCapture` is created with both references and the expected/captured amounts, and no change is made to attempt or intent state.

2. **Given** a duplicate capture exception is recorded, **When** an operator views the reconciliation exceptions list, **Then** the exception is visible with both provider references, amounts, and a `pending_compensation` status indicator.

3. **Given** a succeeded callback arrives with `provider_reference_id = ref-001` for an attempt that already has `provider_reference_id = ref-001`, **When** `handleOutcome()` is called, **Then** Guard A (idempotent replay) fires and returns silently — no duplicate-capture exception is created.

---

### User Story 4 — Deadlock Detection and Bounded Retry (Priority: P3)

Under high concurrency (many users attempting to pay simultaneously), database row-lock contention can cause a deadlock between two concurrent transactions. Rather than returning a generic server error, the system must detect database deadlocks, retry the transaction a bounded number of times with brief backoff, and only fail with a meaningful error after all retries are exhausted. This ensures that legitimate concurrent payments succeed in the vast majority of cases.

**Why this priority**: Deadlocks are rare under normal load but catastrophic when they surface as unexplained failures. Bounded retry makes the system robust under burst load.

**Independent Test**: Can be fully tested by: (a) simulating concurrent requests that would acquire overlapping row locks; (b) verifying that at least one request succeeds; (c) verifying that after max retries, the system returns `HTTP 409` with code `CONCURRENT_MODIFICATION`; (d) verifying that the retry count and backoff are configurable.

**Acceptance Scenarios**:

1. **Given** two concurrent transactions acquire conflicting row locks and a deadlock occurs, **When** the system detects the deadlock, **Then** it automatically retries the transaction (up to the configured maximum) with exponential backoff before propagating the error.

2. **Given** a deadlock retry succeeds on the second attempt, **When** the transaction completes, **Then** the caller receives the normal success response — the retry is transparent.

3. **Given** a deadlock persists beyond the configured maximum retries, **When** all retries are exhausted, **Then** the system returns `HTTP 409` with error code `CONCURRENT_MODIFICATION`, and the retry attempts are logged for observability.

4. **Given** two concurrent requests for the same payment intent, **When** both acquire locks, **Then** they acquire locks in a consistent order (intent row before attempt row) — preventing deadlocks through consistent lock ordering.

---

### Edge Cases

- What happens when Redis is unavailable? → Fall through to PostgreSQL immediately; no Redis errors surface to callers.
- What happens if crash recovery fires on an attempt where Transaction 2 already committed (race condition)? → `handleOutcome()` is idempotent (Guard A/B) — second application is a no-op or raises appropriate exception.
- What happens when a SUBMITTING attempt is being processed by crash recovery AND a callback arrives simultaneously? → Idempotency key on the outcome handler prevents double-processing; last writer wins within the state machine guards.
- What happens when a duplicate capture amount exactly equals the original amount (full duplicate)? → Create `DuplicateCapture` exception with `captured_amount_minor = intent.amount_minor` on the duplicate; still non-blocking.
- What happens when the idempotency record TTL has not yet expired in Redis but the DB record was deleted? → Redis response is returned; DB deletion of idempotency records is a non-permitted operation.

---

## Requirements *(mandatory)*

### Functional Requirements

**US1 — Idempotency Cache Layer**

- **FR-001**: The system MUST implement a `PaymentIdempotencyService` that stores idempotency records durably in PostgreSQL and accelerates lookups via Redis.
- **FR-002**: The system MUST bind each idempotency record to: `actor_id`, operation scope (e.g., `payment.intent.create`), client-supplied `Idempotency-Key`, and a SHA-256 hash of the canonical request body.
- **FR-003**: The system MUST reject a request that reuses an existing `Idempotency-Key` for a different request body with `HTTP 422` and error code `IDEMPOTENCY_KEY_MISMATCH`.
- **FR-004**: The system MUST return the cached serialized response when an idempotency record is found — without re-executing the operation.
- **FR-005**: The system MUST fall back from Redis to PostgreSQL on cache miss, and repopulate Redis from the DB record.
- **FR-006**: Idempotency records MUST be retained in PostgreSQL for at least 90 days regardless of Redis TTL.
- **FR-007**: The system MUST prevent concurrent duplicate processing using a distributed in-flight guard (Redis lock or DB advisory lock), returning the result of the first execution to any concurrent duplicate.

**US2 — Crash Recovery**

- **FR-008**: The system MUST implement a `PaymentAttemptCrashRecoveryJob` that runs periodically and detects attempts stuck in `SUBMITTING` state beyond a configurable threshold.
- **FR-009**: The recovery job MUST call the adapter's `queryPaymentStatus()` using the attempt's durable `operation_id` to determine the provider's authoritative outcome.
- **FR-010**: The recovery job MUST apply the provider's response through the existing `handleOutcome()` path — reusing all existing state machine guards and ledger posting logic.
- **FR-011**: When an adapter does not support `QueryPayment` capability, the recovery job MUST transition the attempt to `unknown`, create a `ReconciliationException(UnknownOutcome)`, and emit an operator alert.
- **FR-012**: The crash recovery threshold MUST be configurable via the platform configuration system (key: `payment.crash_recovery_threshold_seconds`, default: `60`).

**US3 — Duplicate Capture**

- **FR-013**: The system MUST add a `DuplicateCapture` case to `ReconciliationExceptionType`.
- **FR-014**: In `handleOutcome()`, when an incoming `succeeded` outcome has a non-null `provider_reference_id` that differs from the already-recorded `provider_reference_id` on an already-`succeeded` attempt, the system MUST create a `ReconciliationException(DuplicateCapture)` and return without modifying any state.
- **FR-015**: The duplicate-capture exception MUST record both provider references, the intent's expected amount, and the duplicate's captured amount.

**US4 — Deadlock Retry**

- **FR-016**: The system MUST wrap payment DB transactions with a deadlock retry helper that re-attempts the transaction up to `payment.deadlock_max_retries` times (default: `3`) on detection of a database deadlock.
- **FR-017**: Deadlock retries MUST use exponential backoff starting at `payment.deadlock_retry_base_ms` (default: `10`ms).
- **FR-018**: After exhausting retries, the system MUST throw a `ConcurrentModificationException` which translates to `HTTP 409` with error code `CONCURRENT_MODIFICATION`.
- **FR-019**: All deadlock retries MUST be logged at `warning` level with intent/attempt IDs and attempt count for observability.
- **FR-020**: DB transactions that acquire multiple row locks MUST acquire them in consistent order: intent row first, then attempt row.

### Key Entities

- **IdempotencyRecord**: Durable store of a processed request and its response. Fields: `id` (UUID), `actor_id`, `scope` (operation name), `idempotency_key`, `request_hash` (SHA-256), `response_status` (HTTP status), `response_body` (JSON), `created_at`, `expires_at`.
- **PaymentAttempt** (existing, extended): now requires crash-recovery job awareness. Field `initiated_at` (already present) determines how long the attempt has been in `SUBMITTING` state.
- **ReconciliationException** (existing, extended): new type `DuplicateCapture` with additional field `duplicate_provider_reference_id`.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Under concurrent stress test (100 simultaneous identical payment requests), exactly one payment intent is created and exactly one financial effect is produced — zero duplicates.
- **SC-002**: After Redis is flushed and a retry arrives for a previously completed operation, the correct cached response is returned in under 100ms — with no re-execution of the operation.
- **SC-003**: 100% of attempts stuck in `SUBMITTING` state for over the configured threshold are detected and resolved by the crash recovery job within one scheduling cycle.
- **SC-004**: When a provider sends a duplicate success notification with a different reference, a `DuplicateCapture` reconciliation exception is created in 100% of cases — zero suppressed.
- **SC-005**: Under concurrent load causing database deadlocks, the bounded retry mechanism resolves the conflict within 3 retries in ≥ 95% of cases — callers experience a seamless response, not a 409 error.
- **SC-006**: All idempotency records are retained for 90 days regardless of cache layer state — zero data loss on Redis restart.

---

## Assumptions

- P06 is COMPLETE/RELEASE READY: `operation_id` (UUID v7) exists on `payment_attempts`, `isTerminal()` correctly returns `false` for `Unknown`, and `handleOutcome()` implements Guards A, B, and C.
- "Hybrid Option C" from the source architecture document refers to: PostgreSQL as the authoritative durability layer, Redis as a fast short-circuit cache, with Redis misses falling back to PostgreSQL. No dedicated idempotency microservice is required.
- The `payment_idempotency_records` table belongs to the `payment` PostgreSQL schema, consistent with all other Payment module tables.
- Idempotency enforcement applies to the three state-changing payment endpoints: `POST /v1/payment/intents`, `POST /v1/payment/intents/{id}/attempts`, and `POST /v1/payment/intents/{id}/attempts/{id}/outcome`.
- The crash recovery job runs every minute via Laravel Horizon scheduler. More frequent execution would overload provider APIs; less frequent execution leaves too large a recovery window.
- `DuplicateCapture` is distinct from `ExcessCapture` (P06 Guard C): `ExcessCapture` is when the captured amount slightly exceeds the expected amount; `DuplicateCapture` is when the provider confirms the same payment was processed a second time under a different reference.
- Lock ordering (intent before attempt) is enforced within `PaymentOrchestrationService`; cross-module locking (e.g., Wallet holds) follows the existing `WalletModuleContract` interface and is outside P07 scope.
- `queryPaymentStatus()` on adapters that lack `QueryPayment` capability throws `CapabilityNotSupportedException` or returns a "not supported" result — the recovery job must handle this gracefully.
- The source document `OleKard_Shared_Payment_Architecture.docx v1.1` is not directly accessible. Decisions are derived from predecessor spec artifacts (P01–P06 research and plan files), the constitution, and the existing codebase.
