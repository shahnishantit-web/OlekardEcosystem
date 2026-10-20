# ADR-004: Idempotency Strategy — PostgreSQL Authoritative + Redis Read-Through Cache

**Status**: Accepted

**Date**: 2026-08-18

**Deciders**: Platform Team

## Context

State-changing financial API endpoints (FR-033) require idempotency: duplicate requests with the same `Idempotency-Key` header must return the original response without re-executing the operation. The platform needs a strategy that is:

- Durable (survives Redis failure or eviction)
- Performant (low latency for repeat requests)
- Financially safe (Redis is never the source of truth for financial operations)

Three options were evaluated:

1. **PostgreSQL only** — durable but adds DB round-trip on every idempotent endpoint
2. **Redis only** — fast but vulnerable to eviction, restart, or data loss
3. **PostgreSQL authoritative + Redis read-through cache** — durability from PostgreSQL, performance from Redis

## Decision

Adopt **PostgreSQL as the authoritative store** with **Redis as a read-through cache**:

- Idempotency keys stored in `platform.idempotency_keys` table with a `UNIQUE(actor_id, idempotency_key)` composite index
- Redis caches completed responses keyed by `idempotency:{actor_id}:{key}` with TTL
- On cache miss: fall back to authoritative PostgreSQL lookup
- Redis is populated after PostgreSQL commit, never before
- Lock acquisition uses PostgreSQL `INSERT ... ON CONFLICT DO NOTHING` (atomic, no Redis dependency for locking)

**Critical financial safety rule**: Redis is NEVER the source of truth. A cache miss that falls back to PostgreSQL and finds no record means the request is new — it never means "allow re-execution because Redis is down."

## Consequences

### Positive

- Survives Redis failure: PostgreSQL fallback handles all requests
- Low latency for common case: Redis cache hit on repeat requests
- `UNIQUE(actor_id, idempotency_key)` prevents duplicate processing at DB level even under concurrent requests
- Actor-scoped uniqueness: same key from different actors is not a collision (FR-046)

### Negative

- Two storage systems to operate and monitor
- Cache invalidation edge case: if PostgreSQL write succeeds but Redis write fails, next request hits PostgreSQL (correct, but slightly slower)

### Neutral

- Stale `processing` records (from crashed workers) require fencing before reset; simple delete-and-retry is prohibited (CHK014 resolution)

## Alternatives Considered

| Alternative | Reason Rejected |
|-------------|-----------------|
| Redis only | Eviction or restart causes idempotency loss; financially unacceptable |
| PostgreSQL only | Adds DB query on every idempotent request; acceptable latency impact but cache benefit foregone |
| Global unique key (no actor scope) | Allows key collision across actors; rejected in CHK007 resolution |
