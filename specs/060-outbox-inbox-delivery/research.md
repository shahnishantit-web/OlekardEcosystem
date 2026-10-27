# Research: P11 — Outbox Inbox Notifications and Domain Delivery

**Date**: 2026-10-24 | **Feature**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md)

---

## Decision 1: Consumer event-wiring mechanism

**Decision**: Use Laravel event listeners (registered via `Event::listen()` in `PaymentServiceProvider`) to subscribe to `payment.confirmed`, `payment.failed`, `payment.reversed`, and `refund.confirmed` events dispatched by `InProcessDriver::dispatch()`.

**Rationale**: `InProcessDriver` already calls `Event::dispatch(event_name, payload)` for every outbox event it processes. All that is missing is registered listeners. This requires zero changes to the driver or dispatcher — just new listener classes and their registration.

**Alternatives considered**:
- Dedicated job per event type dispatched from the driver — adds a second async hop and complicates idempotency; rejected in favour of the simpler in-process listener.
- Custom driver implementation — would require changing how the `OutboxDispatcherJob` works; rejected as overengineering.

---

## Decision 2: Consumer deduplication strategy

**Decision**: Reuse existing domain-level unique constraints rather than introducing a generic `InboxDeduplicationRecord` table.

- **Notification**: `(source_event_id, notification_type)` UNIQUE on `notification.notifications` (existing)
- **Allocation**: `idempotency_key` UNIQUE on `payment.payment_allocations` (existing); use `allocationReference` from `payment.confirmed` payload as key

**Rationale**: Both tables already enforce deduplication at the DB level. Adding a generic inbox table would duplicate the same guarantee and create a two-phase commit problem (both the inbox record and the domain effect would need to be atomically committed). The constraint-on-the-effect-itself is the correct approach per the Transactional Outbox/Inbox pattern.

**Alternatives considered**:
- Generic `platform.inbox_deduplication_records` table — adds schema complexity and a two-phase risk; rejected.
- Application-level `findBySourceEventId()` check before insert — race-prone without a unique constraint; rejected.

---

## Decision 3: Causation ID interface extension

**Decision**: Add an optional default method `causationId(): ?string` returning `null` to `IntegrationEventInterface`. Events that carry causation must implement it explicitly. `OutboxEventPublisher.buildRow()` calls `causationId()` via duck-typing (using `method_exists`) to preserve backward compatibility.

**Rationale**: PHP 8 interfaces cannot have default method implementations. Using `method_exists` in `buildRow()` is the standard backward-compatible pattern for this codebase. This avoids requiring all 20+ existing event classes to implement the new method.

**Alternatives considered**:
- New `CausationAwareIntegrationEventInterface extends IntegrationEventInterface` — clean but requires a separate check in `buildRow()`; functionally equivalent but more verbose; deferred to a future refactor when more events need it.
- Add `causationId` as constructor parameter to `BaseIntegrationEvent` — good but P11 scope doesn't include refactoring all events to extend a base class; rejected for now.

---

## Decision 4: Outbox queue health query strategy

**Decision**: `OutboxQueueHealthService` executes a single `GROUP BY event_name` query on `platform.outbox_events` to compute `pending_count`, `failed_count`, `dead_letter_count`, and `min(created_at)` per event type in one round-trip. The `oldest_pending_age_seconds` is derived as `now() - min(created_at)` where `status IN ('pending', 'failed')`.

**Rationale**: The existing `outbox_events_dispatch_idx` (`status, next_attempt_at`) and `outbox_events_aggregate_idx` (`aggregate_id, event_name`) indexes already support filtering by status and event_name. Adding a `status + event_name` composite index (already partially covered) makes the GROUP BY efficient.

**Alternatives considered**:
- Separate queries per event type — N+1 problem; rejected.
- Redis-based counter — adds infrastructure complexity and a consistency risk; rejected.

---

## Decision 5: Outbox dead-letter admin approach

**Decision**: Add new routes under `/v1/operations/outbox/` in `reliability.php` served by a new `OutboxAdminController`. These operate directly on `OutboxEventModel` rows with `status = 'dead_letter'`. Replay sets `status = 'pending'`, resets `attempts = 0`, and writes an audit record. This is distinct from the existing `DeadLetterAdminController` (which manages `DeadLetterItemModel` — the E27 dead-letter store for non-outbox failures).

**Rationale**: The two dead-letter stores serve different purposes: `DeadLetterItemModel` is for general job/processing failures (E27 scope); `OutboxEventModel.dead_letter` is for exhausted-retry outbox events (P09/P11 scope). Mixing them in one controller would violate domain boundaries and confuse operators. Separate routes with a clear URL namespace (`/outbox/`) make the distinction explicit.

**Alternatives considered**:
- Extend `DeadLetterAdminController` with an `outbox=true` query parameter — conflates two different entities; rejected.
- Store outbox dead-letters in `DeadLetterItemModel` — requires `OutboxDispatcherJob` to call `DeadLetterService.capture()` on failure, creating coupling; deferred to a future unified dead-letter ADR.

---

## Decision 6: Causation ID for payment.confirmed flow

**Decision**: When `PaymentOrchestrationService.publishEvent(new PaymentConfirmedEvent(...))` is called, the `PaymentConfirmedEvent` carries no prior event ID (it is a root event initiated by the payment capture outcome). Therefore `causation_id` for `payment.confirmed` = `null`.

When `RefundService.events->publish(new RefundConfirmedEvent(...))` is called, the refund is caused by a specific refund request — not a prior integration event — so `causation_id` = `null` here too.

Future events that ARE caused by prior outbox events (e.g. `fulfilment.allocated` caused by `payment.confirmed`) should carry the source event's outbox ID as `causationId`. This requires the listener to receive the outbox event ID from the dispatcher. In this release, causation ID is wired only at the framework level (interface + publisher); listener-to-event causation threading is deferred to a future release once the `InProcessDriver` passes the outbox row ID to the listener.

**Rationale**: The platform.outbox_events row `id` is assigned inside `OutboxEventPublisher.buildRow()`, which is not visible to the event class at construction time. The clean solution (passing outbox ID to re-dispatched event) requires a driver change outside P11 scope. For P11, causation_id infrastructure is laid (interface + publisher wiring); actual propagation from listener-to-child-event is deferred.

---

## Decision 7: Payment-connection outbox table access

**Decision**: Confirmed that `publishOnConnection($event, 'payment')` writes to `platform.outbox_events` via the `payment` database connection, which is a cross-schema reference within the same PostgreSQL instance. The `OutboxDispatcherJob` reads from `platform` connection `platform.outbox_events` — this is the same physical table. Payment events published via `payment` connection are correctly picked up by the dispatcher.

Evidence: `OutboxEventPublisher::publishOnConnection()` line 28: `DB::connection($connection)->table('platform.outbox_events')->insert(...)`. The `payment` connection points to the same PostgreSQL database with access to the `platform` schema. No separate `payment.outbox_events` table is needed.

**Alternatives considered**:
- Dedicated `payment.outbox_events` table + separate dispatcher — full schema isolation; architecturally cleaner but requires a schema migration and a second dispatcher Horizon supervisor; deferred to a future ADR on per-domain outbox sharding.

---

## Existing infrastructure confirmed

| Component | Location | Status |
|-----------|----------|--------|
| `platform.outbox_events` table | `Platform/Infrastructure/Migrations/…create_platform_outbox_events.php` | ✓ Exists; `causation_id` column present but always null |
| `OutboxEventPublisher` | `Platform/Application/Services/OutboxEventPublisher.php` | ✓ Exists; needs causation_id wiring |
| `OutboxDispatcherJob` | `app/Jobs/OutboxDispatcherJob.php` | ✓ Exists; dead_letter on retry exhaustion |
| `OutboxStuckProcessingCleanupJob` | `app/Jobs/OutboxStuckProcessingCleanupJob.php` | ✓ Exists |
| `InProcessDriver` | `Platform/Infrastructure/EventBus/InProcessDriver.php` | ✓ Exists; dispatches as `Event::dispatch(event_name, payload)` |
| `PaymentConfirmedEvent` + siblings | `Payment/Domain/Events/` | ✓ All 4 events exist |
| Notification deduplication | `notification.notifications (source_event_id, notification_type) UNIQUE` | ✓ Exists |
| Allocation idempotency | `payment.payment_allocations.idempotency_key UNIQUE` | ✓ Exists |
| Dead-letter admin API | `Platform/Infrastructure/Http/Controllers/DeadLetterAdminController.php` | ✓ Exists; operates on `DeadLetterItemModel` (E27 scope) |
| Dead-letter routes | `routes/modules/reliability.php` | ✓ Exists; needs outbox-specific routes appended |

## Open decisions (non-blocking)

| ID | Decision | Status | Impact |
|----|----------|--------|--------|
| OD-1 | Per-domain outbox sharding (dedicated `payment.outbox_events` table) | Deferred — future ADR | None for P11 |
| OD-2 | Listener-to-child-event causation ID threading (requires InProcessDriver to pass outbox row ID) | Deferred to P12+ | causation_id = null for child events in P11 |
| OD-3 | Unifying `DeadLetterItemModel` and `OutboxEventModel.dead_letter` into one store | Deferred — future ADR | Two separate admin UIs in P11 |
