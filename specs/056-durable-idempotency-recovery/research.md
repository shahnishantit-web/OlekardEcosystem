# Research: P07 — Durable Idempotency, Concurrency and Recovery

**Date**: 2026-10-21
**Feature**: [spec.md](spec.md)

---

## Decision 1 — Hybrid Option C: PostgreSQL + Redis Idempotency Architecture

**Decision**: Implement a `PaymentIdempotencyService` backed by:
- **Redis (fast path)**: `Cache::store('redis')` with 24-hour TTL; serialised HTTP response stored as JSON string.
- **PostgreSQL (durable fallback)**: new `payment.payment_idempotency_records` table; 90-day retention; accessed only on Redis miss.
- **In-flight guard**: `Cache::lock('payment:idem:lock:{hash}', 30)` with `block(30)` to serialize concurrent identical requests.

**Rationale**: Redis-only idempotency is lost on Redis restart, violating SC-006 (90-day retention). DB-only is safe but adds a DB round-trip to every mutating payment request. Hybrid Option C — DB is authoritative; Redis is a fast-path short-circuit — is already the approved pattern from predecessor architecture decisions (P01 research) and matches the `idempotency-strategy.md` reference in ADR-E48 backlog.

**Redis key format**: `payment:idem:{sha256(actor_id:scope:idempotency_key)}`
- `actor_id`: the authenticated subject (payer or operator)
- `scope`: operation name, e.g., `payment.intent.create`, `payment.attempt.initiate`, `payment.outcome`
- `idempotency_key`: client-supplied `Idempotency-Key` header value

**In-flight lock key**: `payment:idem:lock:{sha256(actor_id:scope:idempotency_key)}`
- TTL: 30 seconds; blocks concurrent duplicate requests while first request executes.
- After 30 s lock expiry, concurrent request falls through and re-checks DB/Redis.

**Alternatives considered**:
- Redis-only (Option A): Rejected — Redis eviction loses cached responses; DR/restart scenario violates FR-006.
- DB-only (Option B): Rejected — adds a DB round-trip on every request hot path; no in-flight guard without advisory locks.
- Dedicated idempotency microservice: Rejected — violates constitution Principle 14 (no new service boundaries without ADR).

---

## Decision 2 — Request Hash Binding (Payload Mutation Detection)

**Decision**: Compute `$requestHash = hash('sha256', json_encode($canonicalBody))` where `$canonicalBody` is the request body array with keys sorted by name (ksort) and all string values normalised (trimmed). Store in `payment_idempotency_records.request_hash`. On replay, compare incoming hash with stored hash; reject with HTTP 422 + `IDEMPOTENCY_KEY_MISMATCH` if they differ.

**Rationale**: A client that sends `amount=10000` with key `K`, then retries with `amount=20000` and the same key `K` would silently receive the cached 10000-response — giving the impression it accepted the new amount. Request hash binding surfaces the mutation and forces the client to use a new key. This is the behaviour specified in FR-003 and matches Stripe's idempotency semantics (an industry reference standard).

**Scope of hash**: JSON body only (not URL parameters or headers), because URL parameters are path-identified and headers are transport-layer concerns. The `Idempotency-Key` header itself is excluded from the hash (it is the key, not the body).

**Alternatives considered**:
- Hashing the full raw body string: Rejected — whitespace differences and field ordering between retries cause false mismatches.
- Not checking payload hash at all: Rejected — violates FR-003; allows silent payload mutation.

---

## Decision 3 — `payment_idempotency_records` Table Design

**Decision**: New table `payment.payment_idempotency_records` with the following columns:

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID (PK) | UUID v7 |
| `actor_id` | VARCHAR(255) NOT NULL | Authenticated subject |
| `scope` | VARCHAR(64) NOT NULL | Operation name |
| `idempotency_key` | VARCHAR(255) NOT NULL | Client-supplied key |
| `request_hash` | CHAR(64) NOT NULL | SHA-256 hex of canonical body |
| `response_status` | SMALLINT NOT NULL | HTTP status code |
| `response_body` | JSONB NOT NULL | Serialised response payload |
| `created_at` | TIMESTAMPTZ NOT NULL | First execution timestamp |
| `expires_at` | TIMESTAMPTZ NOT NULL | `created_at + 90 days` |

**Index**: `UNIQUE (actor_id, scope, idempotency_key)` — enforces one record per actor+scope+key combination; DB-level guard against race conditions at insert time.

**Cleanup**: A Laravel Artisan command `payment:prune-idempotency-records` deletes rows where `expires_at < NOW()`. Scheduled weekly.

**Alternatives considered**:
- Adding idempotency columns to `payment_intents` / `payment_attempts`: Rejected — those tables already have `idempotency_key` for domain-level dedup; the HTTP-layer cache is separate and stores full serialised responses.
- Using a `platform` schema table: Rejected — idempotency is Payment-module-specific; keeping it in `payment` schema respects module boundaries (Principle 7).

---

## Decision 4 — Crash Recovery Job: Scope and Query Mechanism

**Decision**: New `PaymentAttemptCrashRecoveryJob` (periodic scan, not per-attempt) that:
1. Scans `payment_attempts` for rows with `status = 'submitting'` AND `initiated_at < NOW() - INTERVAL '{threshold} seconds'`.
2. For each row, dispatches a `PaymentAttemptSingleCrashRecoveryJob` to the queue (per-attempt, retryable).

The per-attempt job logic:
- If `provider_reference_id` is NOT NULL (crash after adapter returned): call `adapter->queryPaymentStatus($provider_reference_id)`.
- If `provider_reference_id` IS NULL (crash before or during adapter call): call `adapter->queryPaymentStatus($attempt->operation_id)` — passing `operation_id` as the reference identifier.
  - Adapters that don't recognise the `operation_id` return a `PaymentStatusResult` with status `Unknown`.
  - The job interprets `Unknown` as "provider has no record" → attempt transitions to `Failed` with reason `crash_recovery_no_provider_record`.
- If adapter throws `CapabilityUnsupportedException` or has no adapter bound: attempt transitions to `UNKNOWN` state (human review required) → `ReconciliationException(UnknownOutcome)`.

**Rationale**: The existing `PaymentAttemptTimeoutJob` handles `processing`/`awaiting_action` states. Crash recovery specifically targets `SUBMITTING` attempts — a different window (before provider was contacted vs after). Separating the scan job from the per-attempt job prevents one stuck adapter from blocking the full scan.

**Schedule**: Every 60 seconds via `app/Console/Kernel.php` schedule.

**`queryPaymentStatus()` interface note**: The method signature `queryPaymentStatus(string $providerReferenceId)` accepts `operation_id` as a fallback reference; adapters are documented to return `Unknown` status for unrecognised references. This avoids a breaking interface change.

**Alternatives considered**:
- Scheduling per-attempt jobs at attempt creation time (like `PaymentAttemptTimeoutJob`): Rejected — a scheduled timeout job fires at a known time; a crash recovery job must handle crash windows of unknown duration.
- Modifying `PaymentAdapterInterface` to add `queryByOperationId()`: Considered but deferred — no real bank adapter exists yet; adding a no-op default to the abstract adapter is sufficient for the dev/wallet adapter scope.

---

## Decision 5 — Deadlock Detection and Retry

**Decision**: Implement a `DeadlockRetryTrait` with a `withDeadlockRetry(callable $callback): mixed` helper that wraps `DB::connection('platform')->transaction()` calls in the orchestration service. Catches `\Illuminate\Database\QueryException` where `$e->errorInfo[0]` is PostgreSQL SQLSTATE `40P01` (deadlock detected). Retries up to `$maxRetries` times with exponential backoff (μseconds: `$baseUs * 2^n`).

**Retry configuration**:
- `payment.deadlock_max_retries` (default `3`) — from `ConfigurationServiceInterface`
- `payment.deadlock_retry_base_ms` (default `10`) — base millisecond delay

**Failure behaviour**: After max retries, throws `ConcurrentModificationException` which maps to HTTP 409 with `CONCURRENT_MODIFICATION`.

**Lock order enforcement**: `PaymentOrchestrationService` always acquires the intent row lock (`lockForUpdate()`) before the attempt row lock. This is documented in the service class docblock and enforced by code review. No runtime enforcement mechanism is needed beyond the documented convention and PHPStan.

**Rationale**: Deadlocks in PostgreSQL are SQLSTATE `40P01` and are automatically detected by the DB engine. Laravel's `DB::transaction()` does not retry on deadlock by default. A thin retry wrapper at the service level (not at the DB driver level) allows configurable retry policy with Payment-domain context logging.

**Alternatives considered**:
- Laravel's built-in `DB::transaction($cb, $attempts)`: The second argument IS a retry count, but it catches `\Throwable` broadly and is not deadlock-specific. It also does not do backoff. Rejected in favour of explicit SQLSTATE check.
- Distributed mutex (Redis lock before DB transaction): Rejected — adds Redis dependency to core payment transaction path; Redis failure would block all payments.

---

## Decision 6 — Guard D: Duplicate Capture Detection

**Decision**: Add Guard D to `handleOutcome()` in `PaymentOrchestrationService`, positioned immediately after Guard A (idempotent replay) and Guard B (contradictory evidence):

```
Guard D condition:
  attempt.status === Succeeded
  AND incoming outcome === 'succeeded'
  AND incoming_provider_ref !== null
  AND incoming_provider_ref !== attempt.provider_reference_id
```

**Action**: Create `ReconciliationException(DuplicateCapture)` recording:
- `provider_reference_id` (original)
- `duplicate_provider_reference_id` (incoming)
- `detected_amount_minor` (intent.amount_minor — duplicate charged the same amount)
- `expected_amount_minor` (intent.amount_minor)

Return silently (no throw, no state change). The attempt remains `Succeeded` with the original reference.

**New enum case**: `ReconciliationExceptionType::DuplicateCapture = 'duplicate_capture'`

**Why after Guard A?**: Guard A fires first for `Succeeded→Succeeded` with same/null ref (true idempotent replay — no record needed). Guard D only fires for the false-idempotent case (different provider_ref = real second charge).

**Why NOT a blocking throw?**: The payment is succeeded. Blocking on Guard D would leave a legitimate payment in limbo. The duplicate is the operator's problem to compensate; the original payment is already correct.

**Rationale**: Matches spec FR-014/FR-015. Consistent with Guard C (EXCESS_CAPTURE is also non-blocking). Financial integrity requires evidence to be recorded, not suppressed.

**Alternatives considered**:
- Treating duplicate capture as EXCESS_CAPTURE: Rejected — EXCESS_CAPTURE is for captured amount slightly above intent amount in a single transaction. DUPLICATE_CAPTURE is for a second full-amount provider charge under a different reference. Semantically distinct.
- Throwing an exception to force operator attention: Rejected — the original payment is complete; throwing would look like a payment failure to the caller.

---

## Decision 7 — Where to Apply HTTP-Layer Idempotency

**Decision**: The `PaymentIdempotencyService` is called from the **controller layer** (not as a Laravel middleware) for the three state-changing endpoints:
- `PaymentIntentController::store()` — `POST /v1/payment/intents`
- `PaymentAttemptController::store()` — `POST /v1/payment/intents/{id}/attempts`
- `PaymentAttemptController::outcome()` — `POST /v1/payment/intents/{id}/attempts/{id}/outcome`

The service is injected into controllers. Controllers call `$idempotencyService->resolve($scope, $actorId, $key, $bodyHash)` before the orchestration call. On cache hit, return the cached response immediately. On miss, execute, then store.

**Rationale**: Laravel middleware executes before authentication. Idempotency must be scoped to `actor_id` (authenticated subject), so it must run after auth middleware. Controller-level injection is cleaner than a route-specific middleware that needs to inspect authenticated context. This also makes it explicit per-endpoint (some endpoints are exempt — GET requests, admin callbacks).

**Alternatives considered**:
- Middleware (after auth): Technically possible with `$request->user()`. Rejected — harder to test and mixes HTTP-layer concerns into a standalone middleware class that needs access to the request body before parsing.
- Service provider-level interception: Rejected — too magical; not debuggable.
