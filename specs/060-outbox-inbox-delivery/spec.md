# Feature Specification: P11 — Outbox Inbox Notifications and Domain Delivery

**Feature Branch**: `060-outbox-inbox-delivery`

**Created**: 2026-10-24

**Status**: Draft

**Input**: P11 work package — Outbox/inbox extension for ledger acknowledgement, payment confirmation, and domain fulfilment delivery.

**Required predecessors**: P08 (Ledger Acknowledgement and Fulfilment Handoff), P10 (E25 Payment Callback Ingress)

**Source references**: FD07, FD11, LC05, section 6 event contract

---

## Context

The payment platform already has a transactional outbox writer (`OutboxEventPublisher`) and a dispatcher job (`OutboxDispatcherJob`). Payment domain events (`payment.confirmed`, `payment.failed`, `payment.reversed`, `refund.confirmed`) are published through the outbox after durable state changes. P08 wired the critical `payment.confirmed` event to ledger posting.

P11 closes four residual gaps:
1. **Consumer deduplication**: Downstream domains (notifications, fulfilment, accounting) have no durable record of which events they have processed — repeated delivery risks duplicate allocations or notifications.
2. **Causation chain**: Events do not carry the ID of the prior event that caused them, making distributed tracing incomplete.
3. **Operations visibility**: There is no admin API to inspect dead-lettered events, replay an individual event, or monitor queue lag.
4. **Event schema compatibility**: Consumers have no way to detect or handle an unexpected schema version on arrival.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 — Reliable Downstream Delivery (Priority: P1) 🎯 MVP

The payment platform publishes `payment.confirmed`, `payment.failed`, `payment.reversed`, and `refund.confirmed` events. Each event must reach every subscribed downstream domain (notification, fulfilment, accounting) at least once, even if the dispatcher crashes mid-delivery or a consumer is temporarily unavailable.

**Why this priority**: Without reliable delivery, a successful payment may never trigger the downstream fulfilment that completes the customer's purchase, or the notification that tells them the payment succeeded. This is the foundational guarantee the rest of the pipeline depends on.

**Independent Test**: Can be tested by publishing an event, killing the dispatcher mid-flight, restarting, and asserting the event is re-delivered and processed exactly once.

**Acceptance Scenarios**:

1. **Given** a `payment.confirmed` event has been written to the outbox in the same transaction as the state change, **When** the dispatcher processes it successfully, **Then** the event status is `delivered` and each registered subscriber acknowledges receipt.

2. **Given** a `payment.confirmed` event is being dispatched and the process crashes, **When** the dispatcher restarts, **Then** the stuck event is recovered (after the processing-stale timeout) and re-dispatched without creating a duplicate; the original delivery attempt is not double-counted.

3. **Given** a dispatcher attempts delivery and the consumer throws a transient error, **When** the error count is below the per-event-type retry limit, **Then** the event enters `failed` state with exponential back-off before the next attempt; no event is lost.

4. **Given** a dispatcher has exhausted all retries for an event, **When** the final retry fails, **Then** the event status is `dead_letter`; an audit record is written; no further automatic attempts are made; an operations alert is raised.

---

### User Story 2 — Consumer Deduplication (Priority: P1)

Each downstream domain (notification, fulfilment, accounting) must process each payment event exactly once. If the outbox delivers the same event twice — due to crash-and-retry, manual replay, or network duplication — the downstream effect must not be doubled.

**Why this priority**: Without consumer-side deduplication, a replayed `payment.confirmed` event would trigger a second fulfilment allocation or a second push notification for the same payment — both are unacceptable.

**Independent Test**: Can be tested by delivering the same outbox event ID twice to a consumer handler and asserting the downstream record is created once only; the second delivery is acknowledged without error.

**Acceptance Scenarios**:

1. **Given** a consumer domain receives `payment.confirmed` for a `payment_intent_id` it has already processed, **When** the handler runs, **Then** the handler returns a success acknowledgement without creating a second allocation, notification, or ledger entry; a deduplication log entry is written.

2. **Given** a consumer domain has no prior record for a `payment.confirmed` event, **When** the handler runs, **Then** the downstream effect (allocation, notification, ledger credit) is created exactly once, and the deduplication record is stored in the same transaction.

3. **Given** a consumer domain crashes after creating the downstream effect but before committing the deduplication record, **When** the event is re-delivered, **Then** the consumer detects the already-existing downstream record and skips processing; no duplicate is created.

---

### User Story 3 — Event Causation Chain (Priority: P2)

Every integration event published by a domain must carry the identifier of the event (or domain operation) that caused it. Operators and the tracing system can reconstruct the full chain of events that led to any system state.

**Why this priority**: Without causation links, a `refund.confirmed` event cannot be traced back to the `payment.confirmed` event that enabled it. This is required for audit compliance (P23), regulatory dispute evidence (P22), and distributed debugging.

**Independent Test**: Can be tested by triggering a refund flow and asserting that the resulting `refund.confirmed` outbox record carries the `causation_id` matching the `id` of the `payment.confirmed` event that preceded it.

**Acceptance Scenarios**:

1. **Given** a `payment.confirmed` event is published with event ID `E1`, **When** the fulfilment handler processes it and publishes a `fulfillment.allocated` event, **Then** the new event's `causation_id` = `E1`.

2. **Given** a `payment.confirmed` event causes a `refund.confirmed` event, **When** both events are stored in the outbox, **Then** the `refund.confirmed.causation_id` = `payment.confirmed.id`, and `correlation_id` is the same for both (same payment intent correlation).

3. **Given** an event is published without a known causing event (e.g., initial payment submission), **When** it is stored in the outbox, **Then** `causation_id` is null; this is valid and no error is raised.

---

### User Story 4 — Dead-Letter Management and Audited Replay (Priority: P2)

Operations staff can see all events that have been quarantined in dead-letter status, view their payloads and error history, and replay a specific event after root cause is fixed.

**Why this priority**: Without a repair API, dead-lettered events require direct database manipulation to replay — risky, unaudited, and operator-unfriendly. Each dead-lettered `payment.confirmed` event represents a pending fulfilment that will never complete automatically.

**Independent Test**: Can be tested by dead-lettering a test event, calling the replay endpoint, and asserting the event re-enters `pending` state and is re-dispatched; the replay action is recorded in the audit log.

**Acceptance Scenarios**:

1. **Given** an outbox event has status `dead_letter`, **When** an authorised operator calls the replay endpoint with the event ID, **Then** the event status is set to `pending`, `attempts` is reset to `0`, and an audit record of the replay action is written with the operator's actor ID.

2. **Given** an operator queries the dead-letter list, **When** they request events for a given `correlation_id` or `event_name`, **Then** they receive a paginated list with `event_name`, `aggregate_id`, `attempts`, `last_error`, `created_at`, and `dead_lettered_at`.

3. **Given** an operator replays an event that has already been `delivered`, **When** the replay is requested, **Then** the request is rejected with a meaningful error; double-replay of delivered events is not permitted unless the consumer's idempotency record is also cleared first.

---

### User Story 5 — Queue Health Observability (Priority: P3)

Operators can query real-time queue health metrics: age of the oldest unprocessed event per event type, per-event-type retry rates, and dead-letter counts. This feeds monitoring dashboards and alerting rules.

**Why this priority**: Lag metrics are necessary for SLA monitoring (SC-004 below). Without them, a silently backed-up outbox will go undetected until customers report missing notifications.

**Independent Test**: Can be tested by inserting events with a known `created_at` timestamp and asserting the lag API returns the correct age in seconds.

**Acceptance Scenarios**:

1. **Given** there are unprocessed outbox events with varying ages, **When** an authorised operator calls the queue-health endpoint, **Then** the response includes `oldest_pending_age_seconds`, `pending_count`, `dead_letter_count`, and `failed_count` per `event_name`.

2. **Given** an event has been in `processing` state beyond the configured stale threshold, **When** the metrics endpoint is queried, **Then** it reports the event as `stuck` with its age; the existing `OutboxStuckProcessingCleanupJob` is eligible to recover it.

3. **Given** all events are in `delivered` state, **When** the queue-health endpoint is queried, **Then** all lag metrics are zero or absent; no false-positive alerts are raised.

---

### Edge Cases

- What happens when an event has `causation_id` pointing to an event that does not exist in the outbox (published by a module that uses a different outbox store)? → `causation_id` is stored as-is; referential integrity is not enforced; the value is informational only.
- What happens when two consumers register the same `(source_event_id, consumer_name)` deduplication key in a race? → The unique constraint rejects the second insert; the second consumer invocation returns gracefully (already processed).
- What happens when an event payload fails JSON deserialization at the consumer? → Consumer marks the event `dead_letter` immediately (no retry for schema errors); payload is preserved verbatim for operator inspection.
- What happens when an operator replays an event to a consumer whose deduplication record exists? → The consumer's inbox deduplication check fires and returns a "already processed" response; no duplicate effect is created.
- What happens when the outbox table has a very large number of dead-letter events and the replay endpoint is called without pagination? → Endpoint enforces a per-request replay limit (configurable, default 1 event per call for safety); bulk replay is an admin-only batch operation.

---

## Requirements *(mandatory)*

### Functional Requirements

**Outbox durability**

- **FR-001**: The outbox writer MUST insert the event record in the same database transaction as the domain state change it records; no event MAY be inserted after the transaction commits.
- **FR-002**: The outbox dispatcher MUST use `SELECT FOR UPDATE SKIP LOCKED` (or equivalent) to claim events for processing, preventing multiple dispatcher instances from processing the same event.
- **FR-003**: The outbox dispatcher MUST apply exponential back-off between retry attempts; the back-off ceiling MUST be configurable.
- **FR-004**: An event MUST transition to `dead_letter` status after exhausting its per-event-type retry limit; `dead_letter` status is terminal without operator-initiated replay.
- **FR-005**: The outbox writer MUST set `causation_id` on the new event record when the publishing context provides a prior event ID; when no prior event ID is available, `causation_id` MUST be null.

**Consumer deduplication**

- **FR-006**: Each subscribed consumer domain MUST maintain a deduplication record keyed on `(source_event_id, consumer_name)`; the deduplication record MUST be committed in the same transaction as the downstream domain effect.
- **FR-007**: When a consumer receives an event whose `(source_event_id, consumer_name)` pair is already recorded, it MUST acknowledge the event as successfully processed and MUST NOT create a second downstream effect.
- **FR-008**: The deduplication record MUST store at minimum: `source_event_id`, `consumer_name`, `event_name`, `processed_at`, and `correlation_id`.

**Event schema versioning**

- **FR-009**: Every outbox event record MUST carry an `event_version` field (integer ≥ 1). The outbox writer MUST default to version `1` when the event class does not declare a version.
- **FR-010**: When a consumer receives an event with an `event_version` it does not recognise (higher than supported), it MUST quarantine the event to `dead_letter` immediately without attempting processing and MUST log the version mismatch with the event ID.

**Operations and repair**

- **FR-011**: An authorised operations API endpoint MUST allow retrieval of dead-letter events, filterable by `event_name` and `correlation_id`, paginated, with `last_error` and `created_at` included.
- **FR-012**: An authorised operations API endpoint MUST allow replay of a single dead-letter event; replay MUST reset `status` to `pending`, reset `attempts` to `0`, and write an audit record including the operator's actor ID and the event ID.
- **FR-013**: An authorised operations API endpoint MUST return real-time queue health metrics per `event_name`: `pending_count`, `failed_count`, `dead_letter_count`, and `oldest_pending_age_seconds`.
- **FR-014**: Replay of an event that is not in `dead_letter` status MUST be rejected with a descriptive error; no state change is made.

**Minimal personal data**

- **FR-015**: Event payloads MUST NOT include names, addresses, phone numbers, biometric data, or other personal identifiers beyond the actor/payer UUID. Financial amounts, currency codes, purpose references, and UUIDs are permitted.

### Key Entities *(include if feature involves data)*

- **OutboxEvent**: Durable record of a domain event pending delivery; attributes include `id`, `event_name`, `event_version`, `aggregate_type`, `aggregate_id`, `payload`, `status`, `attempts`, `max_attempts`, `causation_id`, `correlation_id`, `next_attempt_at`, `last_error`, `created_at`, `delivered_at`.
- **InboxDeduplicationRecord**: Consumer-side deduplication log; attributes include `id`, `source_event_id`, `consumer_name`, `event_name`, `correlation_id`, `processed_at`.
- **OutboxQueueHealth** (read model): Per-event-type aggregate: `event_name`, `pending_count`, `failed_count`, `dead_letter_count`, `oldest_pending_age_seconds`, `stuck_count`.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A crash immediately after the domain state change and before the dispatcher runs causes no lost events — every event written within a committed transaction is eventually delivered.
- **SC-002**: Delivering the same event ID twice to any consumer produces exactly one downstream effect; the second delivery completes in under 50 ms with no database write beyond the deduplication check.
- **SC-003**: Every outbox event published as part of a causal chain carries a non-null `causation_id` pointing to its direct predecessor event; this is verifiable for 100% of chained payment flows.
- **SC-004**: The queue-health API reports `oldest_pending_age_seconds` that correctly reflects wall-clock age; operators can detect a queue lag > 5 minutes within one API poll cycle.
- **SC-005**: Replaying a dead-letter event results in the event entering the normal dispatch pipeline; the replay is recorded in the audit log with the operator's identity; no audit record = no replay occurred.
- **SC-006**: Event payloads stored in the outbox contain no unmasked personal data fields beyond actor/payer UUIDs, amounts, currencies, and purpose references.

---

## Assumptions

- The existing `platform.outbox_events` table schema (with `status`, `attempts`, `max_attempts`, `causation_id`, `correlation_id`, `event_version`) is already migration-stable; P11 extends it with additional consumer-side tables rather than altering the producer-side table.
- P08's `payment.confirmed` outbox publishing is working correctly; P11 adds the consumer-side inbox and the causation_id setter, not the publisher.
- The Payment and Platform modules share a PostgreSQL instance, allowing a Payment-schema outbox table to be queried by the Platform dispatcher if needed; or the existing `publishOnConnection('payment')` mechanism remains unchanged.
- The operations repair API is admin-only and gated behind an existing admin authentication and authorization layer (inherited from E04/E33); no new auth mechanism is required.
- "Downstream domain" consumers for the initial P11 scope are: (a) Notification delivery (maps `payment.confirmed` → customer notification), (b) Fulfilment allocation recording. Additional consumers (e.g., settlement, analytics) follow the same inbox contract and can be added without P11 changes.
- No exactly-once transport guarantee is claimed or required; at-least-once delivery with consumer-side idempotency provides the required end-to-end exactly-once effect.
- FD07/FD11/LC05 source documents are not directly accessible in the repository; the section 6 event contract from the architecture document is the authoritative reference for event naming (`module.Aggregate.VerbPastTense` dot-notation PascalCase).
- Event schema version compatibility is handled by quarantine (dead_letter) when version is unrecognised; forward-compatible schema evolution (adding optional fields) does not require a version bump and consumers MUST tolerate unknown fields.
- (ADR-P11-001) FR-008 field requirements (`consumer_name`, `event_name`, `processed_at`, `correlation_id` on the deduplication record) are satisfied by semantic equivalence via existing table unique constraints: `notification.notifications (source_event_id, notification_type)` serves the FR-006 keying guarantee (`notification_type` is the consumer-name proxy; `created_at` is the processed-at proxy); `payment.payment_allocations (idempotency_key)` provides the allocation dedup guarantee. `event_name` and `correlation_id` are not stored on the dedup row in this release — they remain available on the originating `platform.outbox_events` row for audit purposes. A generic `InboxDeduplicationRecord` table carrying all FR-008 fields is deferred to a future release once cross-consumer audit requirements are confirmed (research.md Decision 2). This is a documented architectural decision; it does not relax the no-duplicate-effect guarantee of FR-007.
