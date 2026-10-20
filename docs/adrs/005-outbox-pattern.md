# ADR-005: Outbox Pattern — Atomic Domain State + Event in Same DB Transaction

**Status**: Accepted

**Date**: 2026-08-18

**Deciders**: Platform Team

## Context

Integration events (e.g., `Wallet.Transfer.Completed`) must be reliably published to downstream consumers when a domain operation succeeds. The naive approach — commit the domain state, then publish to a message broker — introduces a dual-write problem: the broker publish can fail after the DB commit, leaving domain state changed but no event dispatched.

Two reliable publishing patterns were considered:

1. **Transactional Outbox** — INSERT event row into `platform.outbox_events` in the same DB transaction as domain state; a separate worker polls and dispatches
2. **Change Data Capture (CDC)** — Debezium reads the PostgreSQL WAL and publishes changes; eliminates polling but adds infrastructure complexity

## Decision

Adopt the **Transactional Outbox Pattern**:

- `OutboxEventPublisher::publish()` must be called while an active DB transaction is open; if no transaction is active, it throws `\LogicException` — this enforces atomicity at the application level
- The domain state change (e.g., wallet balance update) and the `INSERT INTO platform.outbox_events` occur in the same `DB::transaction()` block
- On commit: the outbox row exists with `status='pending'`
- On rollback: the outbox row is rolled back — no event is dispatched for a failed operation (FINANCIAL SAFETY invariant)
- `OutboxDispatcherJob` polls for `pending` rows, marks them `processing`, dispatches, then marks `delivered`
- Stuck-processing recovery: a scheduled cleanup job resets records stuck in `processing` after a configurable timeout (FR-182); it uses a fencing mechanism to prevent the original worker from committing after ownership is transferred

## Consequences

### Positive

- Atomicity guaranteed: domain state change and event publication are one PostgreSQL transaction
- Rollback is free: failed financial operations leave zero outbox rows, no phantom events
- No external broker dependency for correctness (broker failure delays delivery but doesn't corrupt state)
- Exactly-once delivery via idempotent consumers + `entryExists()` check at ledger level

### Negative

- Polling introduces latency: events are not dispatched instantly (latency = polling interval)
- Outbox table grows until cleanup; requires periodic archival
- Worker crashes can cause `processing` rows to require manual or automated recovery

### Neutral

- CDC (Debezium) would eliminate polling latency but requires a Kafka cluster and WAL-level PostgreSQL configuration — deferred to a future ADR if real-time event latency becomes a requirement

## Alternatives Considered

| Alternative | Reason Rejected |
|-------------|-----------------|
| Publish after DB commit (naive) | Dual-write: broker publish failure after commit leaves domain state changed with no event |
| CDC / Debezium | Operational complexity too high for Platform Foundation; deferred |
| Saga / choreography without outbox | No guaranteed delivery; compensating transactions needed for every failure path |
