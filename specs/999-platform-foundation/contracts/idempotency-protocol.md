# Contract: Idempotency Protocol

**Feature**: Platform Foundation | **Version**: 1.0.0 | **Date**: 2026-08-16

All state-changing financial API operations MUST implement this protocol. (FR-043 – FR-048)

---

## Request Requirements

- Client MUST include `Idempotency-Key: {uuid_v7}` header on all state-changing financial requests.
- The key MUST be a UUID v7. No other format is accepted (FR-043).
- The same key MUST NOT be reused for different operations by the same actor.
- Client SHOULD generate a new UUID v7 per distinct operation attempt.

---

## Full Request → Response Flow

```
CLIENT                         API MIDDLEWARE                    PostgreSQL              Redis
  │                                │                                │                     │
  ├─ POST /v1/wallets/transfers ──►│                                │                     │
  │  Idempotency-Key: {key}        │                                │                     │
  │                                ├─ GET idempotency:{actor}:{key}─────────────────────►│
  │                                │                                │          HIT ──────►│
  │                                │◄──────────────────── cached response (HIT) ─────────┤
  │◄── 200 OK (cached response) ───┤                                │                     │
  │                                │                                │                     │
  │ [Redis MISS path]              │                                │                     │
  │                                │◄─────────────────── MISS ──────────────────────────┤
  │                                ├─ INSERT INTO platform.idempotency_records ──────────►│
  │                                │  ON CONFLICT DO NOTHING RETURNING id                │
  │                                │                                │                     │
  │                                │ [Conflict: already in progress]│                     │
  │                                │──────────────────────── SELECT FOR UPDATE ──────────►│
  │◄── 409 IDEMPOTENCY_IN_PROGRESS─┤                                │                     │
  │                                │                                │                     │
  │ [No conflict: new execution]   │                                │                     │
  │                                ├──────── Execute Operation ─────►│                     │
  │                                │                                │                     │
  │                                │◄────── Operation Result ───────┤                     │
  │                                ├─ UPDATE idempotency_records ───►│                     │
  │                                │  SET response_status, response_body, status=completed│
  │                                ├─ SET idempotency:{actor}:{key} ─────────────────────►│
  │                                │  EX {ttl_seconds}              │                     │
  │◄── 200/201 (fresh response) ───┤                                │                     │
```

---

## Conflict Behaviors

| Scenario | Response | HTTP Status |
|---|---|---|
| Same key, same payload, completed | Cached response (identical to original) | `200` or `201` (same as original) |
| Same key, same payload, in-progress | Error: `IDEMPOTENCY_IN_PROGRESS` | `409` |
| Same key, different payload (hash mismatch) | Error: `IDEMPOTENCY_KEY_MISMATCH` | `409` |
| Same key, different HTTP method | Error: `IDEMPOTENCY_KEY_MISMATCH` | `409` |
| Same key, expired | Treated as new request (key deleted, re-executed) | `200` or `201` |
| Missing key on financial operation | Error: `IDEMPOTENCY_KEY_REQUIRED` | `400` |

---

## Key Uniqueness Scope

Idempotency keys are scoped to `(actor_id, idempotency_key)`. Two different actors may use the same key value without conflict.

For service-to-service calls with no actor, keys are scoped to `(service_id, idempotency_key)`.

---

## TTL and Expiry

- Default TTL: 24 hours (configurable via `platform.configuration_values` key `idempotency.ttl_seconds`).
- Both PostgreSQL record and Redis cache use the same TTL.
- Expired keys are deleted by a scheduled Horizon cleanup job (`IdempotencyCleanupJob`).
- After expiry, the same key may be reused (treated as a fresh request).

---

## Stale Processing Record Fencing

An idempotency record is considered **stale** when `status = 'processing'` AND `processing_started_at < now() - config('idempotency.processing_timeout_seconds')`. Stale records arise when a dispatcher worker crashes mid-execution.

**Safety invariant**: A stale-processing record MUST NOT be reset to allow re-execution without first establishing exclusive ownership that prevents the original (potentially still-running) worker from committing its financial operation.

**Why simple delete-and-retry is unsafe**: If the cleanup job deletes the `processing` record and a new attempt begins, the original worker may still be alive and may successfully commit its financial operation after the new attempt also commits — producing duplicate financial effects.

**Recovery contract**:
1. The cleanup job MUST acquire an exclusive row-level lock on the stale record (e.g., `SELECT ... FOR UPDATE SKIP LOCKED`) before taking any recovery action.
2. With the lock held, the job MUST check whether the record is still stale (`status` is still `processing`). If the worker completed normally while the lock was being acquired, the record is left intact.
3. Only if confirmed stale, the job updates the record to allow retry (incrementing `attempts`; resetting `processing_started_at = NULL`; returning `status` to a retriable state). The `actor_id`, `idempotency_key`, and `request_hash` MUST be preserved.
4. The fencing mechanism (e.g., lock-based, version-token field, worker-ID validation) is an implementation detail provided the above invariant is satisfied: **at most one execution attempt for a given idempotency key may produce a committed financial effect at any point in time**.

## Audit

- Every idempotency replay (cache HIT) MUST be logged at `INFO` level with: key, actor, endpoint, original `created_at`, replay timestamp, correlation ID.
- Duplicate detection MUST be recorded in `platform.audit_events` with action `idempotency.replay`.
- Key conflicts MUST be recorded in `platform.audit_events` with action `idempotency.conflict`.

---

## Middleware Implementation Contract

The `IdempotencyMiddleware` MUST:
1. Extract `Idempotency-Key` header; if missing on a protected route → 400 error.
2. Compute `request_hash = SHA-256(method + "|" + path + "|" + body)`.
3. Check Redis cache.
4. On miss: attempt PostgreSQL insert.
5. On conflict: check if in-progress or completed, respond accordingly.
6. On success: pass request to controller; capture response; persist to PostgreSQL + Redis.
7. NEVER expose the underlying operation logic; middleware is purely a replay/lock layer.

The middleware wraps the controller at HTTP layer. Business logic is unaware of idempotency — it simply executes.
