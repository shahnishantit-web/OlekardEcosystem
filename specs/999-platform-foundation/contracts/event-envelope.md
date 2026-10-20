# Contract: Event Envelope

**Feature**: Platform Foundation | **Version**: 1.0.0 | **Date**: 2026-08-16

All domain events and integration events MUST use this envelope. (FR-051, FR-052, FR-054)

---

## Event Envelope Schema

```json
{
  "id": "019263a8-0000-7000-8000-000000000001",
  "aggregate_id": "019263a8-0000-7000-8000-000000000002",
  "aggregate_type": "Transfer",
  "actor_id": "019263a8-0000-7000-8000-000000000003",
  "correlation_id": "019263a8-0000-7000-8000-000000000004",
  "causation_id": "019263a8-0000-7000-8000-000000000005",
  "occurred_at": "2026-08-16T10:30:00.000Z",
  "event_name": "Wallet.Transfer.Completed",
  "event_version": "1.0.0",
  "payload": {
    "transfer_id": "019263a8-0000-7000-8000-000000000002",
    "amount": 50000,
    "currency": "BWP",
    "status": "completed"
  },
  "metadata": {
    "environment": "production",
    "app_version": "1.0.0",
    "source_module": "Wallet"
  }
}
```

---

## Field Definitions

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | UUID v7 string | Yes | Unique event identifier |
| `aggregate_id` | UUID v7 string | Yes | ID of the domain aggregate that produced the event |
| `aggregate_type` | string (PascalCase) | Yes | e.g., `Transfer`, `User`, `LedgerEntry` |
| `actor_id` | UUID v7 string | No | Null for system-initiated events |
| `correlation_id` | UUID string | Yes | Propagated from the originating HTTP request |
| `causation_id` | UUID string | Conditional | Required (non-null) for financial events (FR-189). May be null for scheduled/system-initiated events with no causal predecessor; when null, `metadata` MUST include a `trigger` key documenting the trigger source (e.g., `"trigger": "scheduled_job"`). |
| `occurred_at` | ISO 8601 UTC string | Yes | When the domain event occurred (not when dispatched) |
| `event_name` | string | Yes | See naming convention below |
| `event_version` | SemVer string | Yes | e.g., `"1.0.0"` |
| `payload` | object | Yes | Event-specific data; schema defined per event name |
| `metadata` | object | Yes | Routing hints, environment context; never business logic |

---

## Naming Convention

Pattern: `{Domain}.{Aggregate}.{PastTenseVerb}`

| Component | Format | Example |
|---|---|---|
| `Domain` | PascalCase module name | `Wallet`, `Identity`, `Ledger`, `Kyc` |
| `Aggregate` | PascalCase aggregate root | `Transfer`, `User`, `Entry`, `Verification` |
| `PastTenseVerb` | PascalCase past-tense verb | `Completed`, `Registered`, `Posted`, `Approved` |

**Valid examples**:
- `Wallet.Transfer.Completed`
- `Wallet.Transfer.Failed`
- `Identity.User.Registered`
- `Identity.Session.Terminated`
- `Ledger.Entry.Posted`
- `Ledger.Entry.Reversed`
- `Kyc.Verification.Approved`
- `Kyc.Verification.Rejected`
- `Configuration.Policy.Updated`
- `Configuration.Policy.Activated`
- `Agent.Onboarding.Completed`

**Invalid** (do not use):
- `WALLET_TRANSFER_COMPLETED` — wrong format
- `wallet.transfer.completed` — wrong case
- `TransferCompleted` — missing domain prefix
- `Wallet.TransferCompleted` — missing aggregate segment

---

## Delivery Guarantee

Integration events delivered via the outbox pattern are **at-least-once** (FR-190). Exactly-once delivery is NOT guaranteed.

Implications:
- A successfully delivered event MAY be dispatched more than once (e.g., if the dispatcher crashes after dispatch but before marking `delivered`).
- Every event consumer MUST be idempotent (see Consumer Idempotency Contract below).
- Business logic MUST NOT rely on receiving an event exactly once; duplicate delivery must produce no additional side effect.

## Ordering Guarantee

Event ordering is **NOT guaranteed** across dispatcher workers (FR-188).

Implications:
- The outbox dispatcher may process events concurrently across multiple Horizon workers.
- A consumer MAY receive `Wallet.Transfer.Failed` before `Wallet.Transfer.Initiated` for the same aggregate.
- Consumers MUST tolerate out-of-order delivery. Strategies include: timestamp comparison (`occurred_at`), versioned aggregate state, or idempotent state machines that ignore stale transitions.
- If a consumer requires strict ordering for a specific workflow, that ordering requirement MUST be handled within the consumer (e.g., by checking aggregate version before applying state changes), not by relying on the dispatcher.

---

## Domain Event vs Integration Event

| Type | Scope | Persistence | Delivery |
|---|---|---|---|
| **Domain Event** | Within module, same process | In-memory only (Laravel event dispatcher) | Synchronous listeners in same request |
| **Integration Event** | Cross-module or cross-service | Persisted in `platform.outbox_events` | Async via Horizon worker |

Rule: Domain events are never persisted. If another module or service needs to react, emit a corresponding Integration Event from the outbox.

---

## Versioning and Compatibility

- Event `event_version` uses SemVer (`MAJOR.MINOR.PATCH`).
- **Backward-compatible changes** (add optional field to payload): increment MINOR.
- **Breaking changes** (remove/rename field): increment MAJOR and emit both old and new event versions during a deprecation window.
- Consumers MUST ignore unknown payload fields (forward-compatibility rule).
- Consumers MUST declare which `event_version` they handle and skip events with incompatible MAJOR versions (FR-190 / CHK036).

**Consumer version declaration mechanism**: Each event consumer class MUST declare a `HANDLED_EVENT_VERSION` class constant set to the MAJOR version it is written to handle:

```php
class WalletTransferCompletedConsumer
{
    public const HANDLED_EVENT_VERSION = 1; // Handles event_version ^1.x.x

    public function handle(IntegrationEventInterface $event): void { ... }
}
```

The outbox dispatcher MUST read `HANDLED_EVENT_VERSION` before delivering an event. If the event's MAJOR version does not match `HANDLED_EVENT_VERSION`, the dispatcher MUST skip the event and emit a `WARNING` log containing the event name, event MAJOR version, and consumer's `HANDLED_EVENT_VERSION`. It MUST NOT count a version-skipped event as a delivery failure or move it to dead-letter.

---

## Consumer Idempotency Contract

Every event consumer MUST:
1. Record the processed `event.id` in a local `processed_events` log or deduplicate table.
2. Before processing: check if `event.id` is already in the log.
3. If already processed: skip and return success (do not re-process).
4. If not yet processed: process, then record the `event.id`.
5. The idempotency check + processing MUST be atomic (within a DB transaction).

---

## Retry and Dead Letter Contract

| Attempt | Delay |
|---|---|
| 1 | Immediate |
| 2 | 30 seconds |
| 3 | 2 minutes |
| 4 | 10 minutes |
| 5 | 1 hour |
| > max_attempts | Move to `dead_letter` status, alert |

- `max_attempts` defaults to 5; configurable per event type via `platform.configuration_values`.
- Dead-letter events are NOT automatically retried. Manual intervention required.
- Dead-letter events MUST generate an audit event and a monitoring alert.

---

## Outbox Dispatch Flow

```
[Domain operation completes]
    │
    ├── DB Transaction begins
    │   ├── Update domain state (e.g., transfer.status = completed)
    │   └── INSERT INTO platform.outbox_events (status='pending')
    └── DB Transaction commits
         │
         [Horizon OutboxDispatcherJob — runs every N seconds]
              │
              ├── SELECT id FROM platform.outbox_events
              │   WHERE status='pending' AND next_attempt_at <= now()
              │   FOR UPDATE SKIP LOCKED LIMIT 100
              │
              ├── UPDATE status='processing' for selected rows
              │
              ├── For each event:
              │   ├── Dispatch to event bus (Redis channel / SQS / etc.)
              │   ├── SUCCESS → UPDATE status='delivered', delivered_at=now()
              │   └── FAILURE → UPDATE attempts++, next_attempt_at=backoff, status='failed' (or 'dead_letter' if max)
              │
              └── Release DB locks
```
