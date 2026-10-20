# ADR-004: Outbox Pattern for At-Least-Once Event Delivery

**Status**: Accepted
**Date**: 2026-08-01

## Context

Integration events (e.g., `Wallet.Transfer.Completed`) must be delivered reliably even if the message broker is temporarily unavailable or the worker crashes mid-dispatch. Without a guarantee, a crash between "domain state saved" and "event published" produces silent data loss.

## Decision

Use the **Transactional Outbox Pattern**:

1. Domain state change and `platform.outbox_events INSERT` happen in the **same PostgreSQL transaction**.
2. If the transaction commits: both the domain state and the outbox record are durable.
3. If the transaction rolls back: neither the domain state nor the outbox record persists.
4. `OutboxDispatcherJob` polls for pending rows, dispatches via the configured `EventBusDriverInterface`, and marks rows `delivered`.
5. `OutboxStuckProcessingCleanupJob` recovers rows stuck in `processing` status (worker crash recovery) by resetting them to `pending` after a configurable timeout (default: 300s safety fallback).

Consumers **must** implement `ProcessedEventRepositoryInterface` in their own schema to deduplicate at-least-once delivery.

## Consequences

**Positive**:
- Zero silent event loss under transactional failure or worker crash.
- At-least-once delivery guarantee with configurable retry/backoff.
- Driver is swappable (in-process for Platform Foundation; broker-backed for future extraction).

**Negative**:
- Consumers must implement idempotent event handling.
- Outbox table grows without periodic cleanup (delivered events should be archived).

## Alternatives Considered

- **Publish-then-commit**: Rejected — if commit fails after publish, domain state is not persisted but event is already sent (phantom event).
- **Change Data Capture (CDC)**: Deferred — architecturally equivalent but requires infrastructure TBD.
