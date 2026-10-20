# ADR-003: Idempotency Strategy

**Status**: Accepted
**Date**: 2026-08-01

## Context

Financial APIs must handle network retries and duplicate submissions without executing operations twice. The idempotency implementation must be safe under concurrent duplicate requests.

## Decision

Idempotency uses `UNIQUE(actor_id, idempotency_key)` — NOT globally unique keys. Two different actors may reuse the same key string value independently.

**Flow**:
1. `INSERT INTO platform.idempotency_records ... ON CONFLICT (actor_id, idempotency_key) DO NOTHING`
2. If 0 rows inserted: `SELECT ... FOR UPDATE SKIP LOCKED` the existing row
3. If `status='processing'`: return 409 `IDEMPOTENCY_IN_PROGRESS`
4. If `status='completed'`: return stored response; warm Redis cache
5. If new insert: return null (proceed with operation)
6. On completion: UPDATE status to completed; cache in Redis

**PostgreSQL is authoritative**. Redis accelerates cache hits but is never the source of truth.

## Consequences

**Positive**:
- Concurrent duplicates are safely serialized via `FOR UPDATE SKIP LOCKED`.
- Per-actor scoping matches financial industry standards (same key, different customer = different operation).
- Redis cache avoids DB round-trips on replay.

**Negative**:
- Requires transaction management discipline: `INSERT ON CONFLICT` must be called within or before any domain state change.
- Redis cache invalidation requires explicit cleanup on TTL expiry.

## Alternatives Considered

- **Global uniqueness**: Rejected — prevents legitimate reuse of the same key string by different actors.
- **Redis-only idempotency**: Rejected — Redis is not durable enough to be the source of truth for financial operations.
